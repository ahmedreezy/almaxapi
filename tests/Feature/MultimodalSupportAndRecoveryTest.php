<?php

namespace Tests\Feature;

use App\Jobs\ProcessSupportMessage;
use App\Models\Group;
use App\Models\PackageRelease;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SupportContact;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Services\Support\OpenAiMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultimodalSupportAndRecoveryTest extends TestCase
{
    public function test_a_greeting_gets_a_natural_greeting_reply(): void
    {
        config()->set('support.platform_sync', true);
        $user = $this->createUser();

        $this->withHeaders($user['headers'])->postJson('/api/support/chat/messages', [
            'body' => 'Good morning',
            'clientMessageId' => (string) Str::uuid(),
        ])->assertOk()
            ->assertJsonPath('processing', 'complete')
            ->assertJsonPath('conversation.messages.1.body', 'Good morning!');

        $this->assertDatabaseHas('support_messages', [
            'sender_type' => 'ai',
            'model' => 'deterministic-greeting',
        ]);
    }

    public function test_platform_chat_accepts_a_private_image_and_queues_interpretation(): void
    {
        Storage::fake('local');
        Queue::fake();
        $user = $this->createUser();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL5WQAAAABJRU5ErkJggg==');
        $image = UploadedFile::fake()->createWithContent('payment-screenshot.png', $png);

        $this->withHeaders($user['headers'])->post('/api/support/chat/messages', [
            'body' => 'Please check this screenshot',
            'clientMessageId' => (string) Str::uuid(),
            'attachments' => [$image],
        ])->assertAccepted()
            ->assertJsonPath('processing', 'queued')
            ->assertJsonPath('conversation.messages.0.attachments.0.kind', 'image');

        $this->assertDatabaseHas('support_message_attachments', ['kind' => 'image', 'status' => 'ready']);
        Queue::assertPushed(ProcessSupportMessage::class);
    }

    public function test_voice_note_is_transcribed_with_the_configured_audio_model(): void
    {
        Storage::fake('local');
        Http::fake(['*/audio/transcriptions' => Http::response(['text' => 'I need help with my receipt.'])]);
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.transcription_model', 'gpt-transcribe');
        $user = $this->createUser();
        $contact = SupportContact::create([
            'user_id' => $user['user']->id,
            'channel' => 'platform',
            'external_id' => 'user:'.$user['user']->id,
        ]);
        $conversation = SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'contact_id' => $contact->id,
            'status' => 'open',
            'mode' => 'ai',
        ]);
        $message = SupportMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'body' => '',
        ]);
        Storage::disk('local')->put('support/test-note.webm', 'audio-bytes');
        $attachment = SupportMessageAttachment::create([
            'public_id' => (string) Str::uuid(),
            'support_message_id' => $message->id,
            'kind' => 'audio',
            'disk' => 'local',
            'path' => 'support/test-note.webm',
            'original_name' => 'voice-note.webm',
            'mime_type' => 'audio/webm',
            'size_bytes' => 11,
            'status' => 'uploaded',
        ]);

        $text = app(OpenAiMediaService::class)->transcribe($attachment);

        $this->assertSame('I need help with my receipt.', $text);
        $this->assertDatabaseHas('support_message_attachments', [
            'id' => $attachment->id,
            'status' => 'ready',
            'transcript' => 'I need help with my receipt.',
        ]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/audio/transcriptions'));
    }

    public function test_admin_takeover_pauses_ai_until_explicit_release(): void
    {
        config()->set('support.platform_sync', false);
        Queue::fake();
        $user = $this->createUser();
        $admin = $this->createAdmin();

        $this->withHeaders($user['headers'])->postJson('/api/support/chat/messages', [
            'body' => 'I need help',
            'clientMessageId' => (string) Str::uuid(),
        ])->assertAccepted();
        $conversation = SupportConversation::firstOrFail();
        Queue::fake();

        $this->withHeaders($admin['headers'])
            ->postJson("/api/support/admin/conversations/{$conversation->id}/takeover")
            ->assertOk()
            ->assertJsonPath('mode', 'human');

        $this->withHeaders($user['headers'])->postJson('/api/support/chat/messages', [
            'body' => 'Here is the reference',
            'clientMessageId' => (string) Str::uuid(),
        ])->assertAccepted()->assertJsonPath('processing', 'human');
        Queue::assertNothingPushed();

        $this->withHeaders($admin['headers'])
            ->postJson("/api/support/admin/conversations/{$conversation->id}/messages", ['body' => 'Thank you. I am checking it now.'])
            ->assertCreated()
            ->assertJsonPath('sender_type', 'admin');

        $this->withHeaders($user['headers'])->postJson('/api/support/chat/messages', [
            'body' => 'Any update?',
            'clientMessageId' => (string) Str::uuid(),
        ])->assertAccepted()->assertJsonPath('processing', 'human');

        $latestInbound = SupportMessage::where('direction', 'inbound')->latest('id')->firstOrFail();
        $this->withHeaders($admin['headers'])
            ->postJson("/api/support/admin/conversations/{$conversation->id}/release")
            ->assertOk()
            ->assertJsonPath('mode', 'ai');
        Queue::assertPushed(ProcessSupportMessage::class, fn ($job) => $job->messageId === $latestInbound->id);
    }

    public function test_failed_release_grants_one_recovery_only_to_confirmed_eligible_users(): void
    {
        $admin = $this->createAdmin();
        $eligible = $this->createUser('0700000001');
        $unconfirmed = $this->createUser('0700000002');
        $group = Group::firstOrFail();
        $publishedAt = now()->subHours(3);
        $cutoff = now()->subHour();

        $eligibleSub = $this->subscriptionFor($eligible['user']->id, $group->id, $publishedAt);
        $unconfirmedSub = $this->subscriptionFor($unconfirmed['user']->id, $group->id, $publishedAt);
        $this->paymentFor($eligibleSub, 'confirmed');
        $this->paymentFor($unconfirmedSub, 'pending');

        $release = PackageRelease::create([
            'public_id' => (string) Str::uuid(),
            'group_id' => $group->id,
            'type' => 'paid',
            'betslip_link' => 'https://example.test/paid',
            'betslip_code' => 'PAID1',
            'published_at' => $publishedAt,
            'cutoff_at' => $cutoff,
            'result' => 'pending',
        ]);

        $this->withHeaders($admin['headers'])
            ->postJson("/api/package-releases/{$release->public_id}/result", [
                'result' => 'failed',
                'recoveryBetslipLink' => 'https://example.test/recovery',
                'recoveryBetslipCode' => 'FREE1',
            ])->assertOk()->assertJsonPath('result', 'failed');

        $this->assertDatabaseCount('package_release_entitlements', 1);
        $this->assertDatabaseHas('package_release_entitlements', ['user_id' => $eligible['user']->id]);
        $this->assertDatabaseMissing('package_release_entitlements', ['user_id' => $unconfirmed['user']->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $eligible['user']->id, 'type' => 'recovery_ticket']);

        $this->withHeaders($eligible['headers'])->getJson('/api/package-releases/recoveries/me')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.release.betslip_code', 'FREE1');

        $this->withHeaders($admin['headers'])
            ->postJson("/api/package-releases/{$release->public_id}/result", [
                'result' => 'failed',
                'recoveryBetslipCode' => 'SECOND',
            ])->assertStatus(409);
        $this->assertDatabaseCount('package_releases', 2);
    }

    public function test_one_recovery_ticket_can_cover_multiple_failed_packages(): void
    {
        $admin = $this->createAdmin();
        $firstUser = $this->createUser('0700000011');
        $secondUser = $this->createUser('0700000012');
        $groups = Group::query()->limit(2)->get();
        $publishedAt = now()->subHours(3);
        $cutoff = now()->subHour();

        $firstSubscription = $this->subscriptionFor($firstUser['user']->id, $groups[0]->id, $publishedAt);
        $secondSubscription = $this->subscriptionFor($secondUser['user']->id, $groups[1]->id, $publishedAt);
        $this->paymentFor($firstSubscription, 'confirmed');
        $this->paymentFor($secondSubscription, 'confirmed');

        $releases = $groups->map(fn (Group $group, int $index) => PackageRelease::create([
            'public_id' => (string) Str::uuid(),
            'group_id' => $group->id,
            'type' => 'paid',
            'betslip_link' => 'https://example.test/paid-'.($index + 1),
            'betslip_code' => 'PAID'.($index + 1),
            'published_at' => $publishedAt,
            'cutoff_at' => $cutoff,
            'result' => 'pending',
        ]));

        $this->withHeaders($admin['headers'])
            ->postJson('/api/package-releases/recovery', [
                'releaseIds' => $releases->pluck('public_id')->all(),
                'recoveryBetslipLink' => 'https://example.test/shared-recovery',
                'recoveryBetslipCode' => 'SHARED1',
            ])->assertOk()
            ->assertJsonPath('recovery.betslip_code', 'SHARED1')
            ->assertJsonCount(2, 'recovery.recovery_sources')
            ->assertJsonCount(2, 'releases');

        foreach ($releases as $release) {
            $this->assertDatabaseHas('package_releases', [
                'id' => $release->id,
                'result' => 'failed',
            ]);
        }
        $this->assertDatabaseCount('package_release_recovery_sources', 2);
        $this->assertDatabaseCount('package_release_entitlements', 2);
        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertDatabaseCount('package_releases', 3);
    }

    private function subscriptionFor(int $userId, int $groupId, $publishedAt): Subscription
    {
        return Subscription::create([
            'user_id' => $userId,
            'group_id' => $groupId,
            'plan_type' => 'weekly',
            'odds_type' => '2',
            'payment_method' => 'mtn',
            'phone' => '0700000000',
            'amount' => 10000,
            'status' => 'active',
            'payment_reference' => 'PAY-'.Str::uuid(),
            'started_at' => $publishedAt->copy()->subHour(),
            'expires_at' => $publishedAt->copy()->addDay(),
        ]);
    }

    private function paymentFor(Subscription $subscription, string $status): Payment
    {
        return Payment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'amount' => $subscription->amount,
            'plan_type' => $subscription->plan_type,
            'payment_method' => $subscription->payment_method,
            'phone' => $subscription->phone,
            'status' => $status,
            'payment_reference' => $subscription->payment_reference,
        ]);
    }
}
