<?php

namespace Tests\Feature;

use App\Jobs\ProcessSupportMessage;
use App\Models\AdminUser;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SupportContact;
use App\Models\SupportConversation;
use App\Models\SupportDailyUsage;
use App\Models\SupportKnowledgeArticle;
use App\Models\SupportMessage;
use App\Models\SupportToolAudit;
use App\Services\Support\SupportQuotaService;
use App\Services\Support\SupportToolExecutor;
use Database\Seeders\SupportKnowledgeSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportSystemTest extends TestCase
{
    private const WEBHOOK_URL = 'https://almax.test/api/support/twilio/inbound';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.twilio.auth_token', 'twilio-test-token');
        config()->set('services.twilio.account_sid', 'AC123');
        config()->set('services.twilio.whatsapp_from', '+14155238886');
        config()->set('services.twilio.inbound_webhook_url', self::WEBHOOK_URL);
        config()->set('support.daily_reply_limit', 2);
    }

    public function test_signed_twilio_message_creates_linked_conversation_and_queues_processing(): void
    {
        Queue::fake();
        $user = $this->createUser('0700000001')['user'];
        $params = [
            'MessageSid' => 'SM-INBOUND-1',
            'From' => 'whatsapp:+256700000001',
            'Body' => 'I paid but did not receive my receipt',
            'ProfileName' => 'Kevin',
            'NumMedia' => '0',
            'WaId' => '256700000001',
        ];

        $this->withHeader('X-Twilio-Signature', $this->signature(self::WEBHOOK_URL, $params))
            ->post('/api/support/twilio/inbound', $params)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $contact = SupportContact::firstOrFail();
        $this->assertSame($user->id, $contact->user_id);
        $this->assertSame('+256700000001', $contact->phone);
        $message = SupportMessage::firstOrFail();
        $this->assertSame('I paid but did not receive my receipt', $message->body);
        Queue::assertPushed(ProcessSupportMessage::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_twilio_webhook_rejects_invalid_signature(): void
    {
        $this->withHeader('X-Twilio-Signature', 'invalid')
            ->post('/api/support/twilio/inbound', ['MessageSid' => 'SM-BAD'])
            ->assertForbidden();

        $this->assertDatabaseCount('support_messages', 0);
    }

    public function test_duplicate_twilio_message_is_idempotent(): void
    {
        Queue::fake();
        $params = ['MessageSid' => 'SM-DUPLICATE', 'From' => 'whatsapp:+256701111111', 'Body' => 'Hello'];
        $signature = $this->signature(self::WEBHOOK_URL, $params);

        $this->withHeader('X-Twilio-Signature', $signature)->post('/api/support/twilio/inbound', $params)->assertOk();
        $this->withHeader('X-Twilio-Signature', $signature)->post('/api/support/twilio/inbound', $params)->assertOk();

        $this->assertDatabaseCount('support_messages', 1);
        Queue::assertPushed(ProcessSupportMessage::class, 1);
    }

    public function test_daily_reply_limit_is_reserved_and_enforced_atomically(): void
    {
        $contact = SupportContact::create([
            'channel' => 'whatsapp',
            'external_id' => '+256702222222',
            'phone' => '+256702222222',
        ]);
        $quota = app(SupportQuotaService::class);

        $this->assertTrue($quota->reserve($contact));
        $quota->consume($contact, 100, 20);
        $this->assertTrue($quota->reserve($contact));
        $quota->consume($contact, 80, 10);
        $this->assertFalse($quota->reserve($contact));

        $usage = SupportDailyUsage::firstOrFail();
        $this->assertSame(2, $usage->replies_used);
        $this->assertSame(180, $usage->input_tokens);
        $this->assertSame(30, $usage->output_tokens);
        $this->assertTrue($quota->claimLimitNotice($contact));
        $this->assertFalse($quota->claimLimitNotice($contact));
    }

    public function test_admin_can_manage_knowledge_and_take_over_a_conversation(): void
    {
        $admin = $this->createAdmin();
        $contact = SupportContact::create([
            'channel' => 'whatsapp',
            'external_id' => '+256703333333',
            'phone' => '+256703333333',
        ]);
        $conversation = SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'contact_id' => $contact->id,
        ]);

        $this->withHeaders($admin['headers'])->postJson('/api/support/admin/knowledge', [
            'title' => 'Payment pending',
            'question' => 'What happens when a payment is pending?',
            'answer' => 'Do not pay twice while we verify the first request.',
            'keywords' => ['payment', 'pending'],
            'locale' => 'en',
            'status' => 'published',
        ])->assertCreated()->assertJsonPath('status', 'published');

        $this->assertDatabaseCount('support_knowledge_articles', 1);
        $this->assertNotNull(SupportKnowledgeArticle::first()->published_at);

        $this->withHeaders($admin['headers'])
            ->patchJson("/api/support/admin/conversations/{$conversation->id}", [
                'mode' => 'human',
                'dailyLimit' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'human')
            ->assertJsonPath('status', 'human');

        $this->assertSame(7, $contact->fresh()->daily_limit_override);
    }

    public function test_human_reply_uses_twilio_without_consuming_ai_quota(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM-OUT-1', 'status' => 'queued'], 201),
        ]);
        $admin = $this->createAdmin();
        $contact = SupportContact::create([
            'channel' => 'whatsapp',
            'external_id' => '+256704444444',
            'phone' => '+256704444444',
        ]);
        $conversation = SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'contact_id' => $contact->id,
        ]);

        $this->withHeaders($admin['headers'])
            ->postJson("/api/support/admin/conversations/{$conversation->id}/reply", ['body' => 'We are checking this for you.'])
            ->assertCreated()
            ->assertJsonPath('sender_type', 'human');

        $this->assertSame('human', $conversation->fresh()->mode);
        $this->assertDatabaseCount('support_daily_usages', 0);
        Http::assertSent(fn ($request) => $request['To'] === 'whatsapp:+256704444444');
    }

    public function test_signed_receipt_is_available_only_for_confirmed_payment(): void
    {
        $user = $this->createUser('0705555555')['user'];
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_type' => 'weekly',
            'odds_type' => '2',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'amount' => 10000,
            'status' => 'active',
        ]);
        $payment = Payment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => 10000,
            'status' => 'confirmed',
            'receipt_number' => 'ALX-RCP-TEST123',
        ]);
        $url = URL::temporarySignedRoute('support.receipt', now()->addMinute(), ['payment' => $payment->id]);

        $this->get($url)->assertOk()->assertSee('ALX-RCP-TEST123');
        $this->get("/api/support/receipts/{$payment->id}")->assertForbidden();
    }

    public function test_first_operational_reply_uses_the_almax_greeting(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM-MEDIA-REPLY', 'status' => 'queued'], 201),
        ]);
        $contact = SupportContact::create([
            'channel' => 'whatsapp',
            'external_id' => '+256705555555',
            'phone' => '+256705555555',
        ]);
        $conversation = SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'contact_id' => $contact->id,
        ]);
        $incoming = SupportMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'body' => '',
            'provider_message_id' => 'SM-MEDIA-IN',
            'metadata' => ['num_media' => 1],
        ]);

        dispatch_sync(new ProcessSupportMessage($incoming->id));

        $reply = SupportMessage::where('direction', 'outbound')->firstOrFail();
        $this->assertStringStartsWith((string) config('support.greeting'), $reply->body);
        $this->assertSame('system', $reply->sender_type);
        $this->assertDatabaseCount('support_daily_usages', 0);
    }

    public function test_ai_can_call_customer_scoped_payment_tool_and_send_one_branded_reply(): void
    {
        config()->set('services.openai.api_key', 'openai-test-key');
        config()->set('services.openai.base_url', 'https://api.openai.test/v1');
        $user = $this->createUser('0706666666')['user'];
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_type' => 'weekly',
            'odds_type' => '2',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'amount' => 10000,
            'status' => 'active',
        ]);
        Payment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => 10000,
            'payment_method' => 'mtn',
            'status' => 'confirmed',
            'payment_reference' => 'ALX-PAY-123',
        ]);
        $contact = SupportContact::create([
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'external_id' => '+256706666666',
            'phone' => '+256706666666',
        ]);
        $conversation = SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'contact_id' => $contact->id,
        ]);
        $incoming = SupportMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'body' => 'Did you receive payment ALX-PAY-123?',
            'provider_message_id' => 'SM-IN-PAYMENT',
        ]);

        $openAiCalls = 0;
        Http::fake(function ($request) use (&$openAiCalls) {
            if (str_contains($request->url(), 'api.openai.test')) {
                $this->assertStringContainsString('Almax Predictions is a football prediction-content service', $request['instructions']);
                $this->assertTrue(collect($request['tools'])->contains(
                    fn (array $tool) => ($tool['name'] ?? '') === 'get_available_packages'
                ));
                $openAiCalls++;
                if ($openAiCalls === 1) {
                    return Http::response([
                        'model' => 'gpt-5.4-mini',
                        'output' => [[
                            'type' => 'function_call',
                            'name' => 'check_payment_status',
                            'call_id' => 'call_payment_1',
                            'arguments' => json_encode(['payment_reference' => 'ALX-PAY-123']),
                        ]],
                        'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
                    ]);
                }

                return Http::response([
                    'model' => 'gpt-5.4-mini',
                    'output' => [[
                        'type' => 'message',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => json_encode([
                                'reply' => 'We received your UGX 10,000 payment and your subscription is active.',
                                'language' => 'en',
                                'category' => 'payment',
                                'sentiment' => 'neutral',
                                'priority' => 'normal',
                                'action' => 'answer',
                                'summary' => 'Customer payment is confirmed.',
                                'requires_human' => false,
                                'resolved' => true,
                            ]),
                        ]],
                    ]],
                    'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
                ]);
            }

            return Http::response(['sid' => 'SM-AI-OUT', 'status' => 'queued'], 201);
        });

        dispatch_sync(new ProcessSupportMessage($incoming->id));

        $outbound = SupportMessage::where('direction', 'outbound')->firstOrFail();
        $this->assertStringStartsWith('Hello, this is Almax Predictions.', $outbound->body);
        $this->assertSame('ai', $outbound->sender_type);
        $this->assertSame(1, SupportDailyUsage::firstOrFail()->replies_used);
        $this->assertSame(220, SupportDailyUsage::first()->input_tokens);
        $this->assertDatabaseCount('support_tool_audits', 1);
        $this->assertTrue(SupportToolAudit::first()->successful);
        $this->assertSame('resolved', $conversation->fresh()->status);
        $this->assertSame(2, $openAiCalls);
    }

    public function test_available_packages_tool_returns_only_current_public_packages_without_paid_content(): void
    {
        $available = Group::create([
            'name' => 'Odds 2 Daily',
            'odds_type' => '2',
            'plan_type' => 'daily',
            'price' => 10000,
            'betslip_link' => 'https://private.example/betslip',
            'betslip_code' => 'PRIVATE-CODE',
            'is_active' => true,
        ]);
        Group::create([
            'name' => 'Hidden Package',
            'odds_type' => '5',
            'plan_type' => 'weekly',
            'price' => 55000,
            'is_active' => false,
        ]);
        $contact = SupportContact::create([
            'channel' => 'whatsapp',
            'external_id' => '+256707777777',
            'phone' => '+256707777777',
        ]);
        $conversation = SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'contact_id' => $contact->id,
        ]);
        $message = SupportMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'body' => 'Which packages are available?',
        ]);

        $result = app(SupportToolExecutor::class)->execute(
            $conversation,
            $message,
            'get_available_packages',
            []
        );

        $this->assertTrue($result['ok']);
        $package = collect($result['packages'])->firstWhere('id', $available->id);
        $this->assertNotNull($package);
        $this->assertSame(10000.0, $package['price']);
        $this->assertFalse(collect($result['packages'])->contains('name', 'Hidden Package'));
        $this->assertTrue(collect($result['packages'])->every(
            fn (array $item) => ! array_key_exists('betslip_link', $item)
                && ! array_key_exists('betslip_code', $item)
        ));
    }

    public function test_baseline_support_knowledge_seeder_is_idempotent(): void
    {
        $this->seed(SupportKnowledgeSeeder::class);
        $this->seed(SupportKnowledgeSeeder::class);

        $this->assertDatabaseCount('support_knowledge_articles', 7);
        $this->assertDatabaseMissing('support_knowledge_articles', ['status' => 'draft']);
    }

    public function test_developer_can_view_anonymized_support_insights(): void
    {
        $developer = AdminUser::create([
            'username' => 'support-insights-dev',
            'role' => 'developer',
            'password_hash' => Hash::make('DevPassword@2026'),
        ]);
        $token = $developer->createToken('support-insights', ['role:developer'])->plainTextToken;
        $contact = SupportContact::create([
            'channel' => 'in_app',
            'external_id' => 'user-42',
            'phone' => '+256700123456',
            'display_name' => 'Private Customer',
        ]);

        SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'contact_id' => $contact->id,
            'status' => 'waiting_human',
            'mode' => 'waiting_human',
            'category' => 'complaint',
            'sentiment' => 'frustrated',
            'priority' => 'high',
            'summary' => 'Payment +256700123456 for private@example.com using ALX-PAY-123 took too long.',
            'last_message_at' => now(),
        ]);
        SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'contact_id' => $contact->id,
            'status' => 'resolved',
            'mode' => 'ai',
            'category' => 'suggestion',
            'sentiment' => 'positive',
            'priority' => 'normal',
            'summary' => 'Customer requested clearer package comparisons.',
            'last_message_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/analytics/developer/support-insights?days=30')
            ->assertOk()
            ->assertJsonPath('summary.total_conversations', 2)
            ->assertJsonPath('summary.feedback_signals', 2)
            ->assertJsonPath('summary.escalated', 1)
            ->assertJsonPath('summary.negative', 1)
            ->assertJsonPath('by_category.complaint.count', 1)
            ->assertJsonPath('by_category.suggestion.count', 1)
            ->assertJsonCount(2, 'recent_feedback')
            ->assertJsonFragment([
                'summary' => 'Payment [phone removed] for [email removed] using [reference removed] took too long.',
            ]);

        $response->assertJsonMissing(['phone' => '+256700123456']);
        $response->assertJsonMissing(['display_name' => 'Private Customer']);
        $response->assertDontSee('private@example.com');
        $response->assertDontSee('ALX-PAY-123');
    }

    public function test_registered_user_can_open_platform_chat_and_send_idempotent_message(): void
    {
        Queue::fake();
        $account = $this->createUser('0708888888');
        $payload = [
            'body' => 'I need help finding my active package.',
            'clientMessageId' => (string) Str::uuid(),
        ];

        $this->getJson('/api/support/chat')->assertUnauthorized();

        $this->withHeaders($account['headers'])
            ->getJson('/api/support/chat')
            ->assertOk()
            ->assertJsonPath('conversation', null);

        $this->withHeaders($account['headers'])
            ->postJson('/api/support/chat/messages', $payload)
            ->assertAccepted()
            ->assertJsonPath('conversation.messages.0.body', $payload['body'])
            ->assertJsonPath('conversation.messages.0.sender', 'user')
            ->assertJsonPath('conversation.waitingForReply', true);

        $contact = SupportContact::firstOrFail();
        $this->assertSame('platform', $contact->channel);
        $this->assertSame($account['user']->id, $contact->user_id);
        $message = SupportMessage::firstOrFail();
        Queue::assertPushed(ProcessSupportMessage::class, fn ($job) => $job->messageId === $message->id);

        $this->withHeaders($account['headers'])
            ->postJson('/api/support/chat/messages', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_platform_chat_reply_is_persisted_without_twilio_delivery(): void
    {
        config()->set('services.openai.api_key', 'openai-test-key');
        config()->set('services.openai.base_url', 'https://api.openai.test/v1');
        $account = $this->createUser('0709999999');
        $contact = SupportContact::create([
            'user_id' => $account['user']->id,
            'channel' => 'platform',
            'external_id' => 'user:'.$account['user']->id,
            'phone' => $account['user']->phone,
        ]);
        $conversation = SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'contact_id' => $contact->id,
        ]);
        $incoming = SupportMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'body' => 'Where do I see my subscription?',
            'provider_message_id' => 'platform:test-message',
        ]);

        Http::fake([
            'api.openai.test/*' => Http::response([
                'model' => 'gpt-5.4-mini',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'reply' => 'Open your account menu and choose View My Subscriptions.',
                            'language' => 'en',
                            'category' => 'subscription',
                            'sentiment' => 'neutral',
                            'priority' => 'normal',
                            'action' => 'answer',
                            'summary' => 'Customer needs subscription navigation help.',
                            'requires_human' => false,
                            'resolved' => false,
                        ]),
                    ]],
                ]],
                'usage' => ['input_tokens' => 30, 'output_tokens' => 12],
            ]),
        ]);

        dispatch_sync(new ProcessSupportMessage($incoming->id));
        dispatch_sync(new ProcessSupportMessage($incoming->id));

        $reply = SupportMessage::where('direction', 'outbound')->firstOrFail();
        $this->assertSame('delivered', $reply->delivery_status);
        $this->assertSame($incoming->id, $reply->metadata['in_reply_to']);
        $this->assertDatabaseCount('support_messages', 2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.twilio.com'));
    }

    private function signature(string $url, array $params): string
    {
        ksort($params, SORT_STRING);
        $payload = $url;
        foreach ($params as $key => $value) {
            $payload .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $payload, 'twilio-test-token', true));
    }
}
