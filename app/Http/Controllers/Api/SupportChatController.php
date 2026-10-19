<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSupportMessage;
use App\Models\SupportContact;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportChatController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $conversation = $this->latestConversation($request);

        return response()->json([
            'conversation' => $conversation ? $this->serializeConversation($conversation) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'clientMessageId' => ['required', 'uuid'],
            'attachments' => ['sometimes', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:'.max((int) config('support.max_image_kilobytes'), (int) config('support.max_audio_kilobytes'))],
            'audioDuration' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
        ]);
        $body = trim((string) ($data['body'] ?? ''));
        $files = $request->file('attachments', []);
        if ($body === '' && count($files) === 0) {
            return response()->json(['message' => 'Please enter a message or attach an image or voice note.'], 422);
        }

        $attachmentSpecs = $this->validateAttachments($files, $data['audioDuration'] ?? null);

        $user = $request->user();
        $providerId = "platform:{$user->id}:{$data['clientMessageId']}";
        $existing = SupportMessage::where('provider_message_id', $providerId)->first();
        if ($existing) {
            return response()->json([
                'conversation' => $this->serializeConversation($existing->conversation),
                'duplicate' => true,
            ]);
        }

        $contact = SupportContact::firstOrCreate(
            ['channel' => 'platform', 'external_id' => "user:{$user->id}"],
            ['user_id' => $user->id, 'phone' => $user->phone, 'display_name' => $user->username]
        );
        $contact->update([
            'user_id' => $user->id,
            'phone' => $user->phone,
            'display_name' => $user->username,
        ]);

        $conversation = $contact->conversations()
            ->where('status', '!=', 'resolved')
            ->latest('id')
            ->first();

        if ($conversation && $conversation->mode === 'ai') {
            $latest = $conversation->messages()->latest('id')->first();
            if ($latest?->direction === 'inbound') {
                $conversation->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                ]);
                $conversation = null;
            }
        }

        $message = DB::transaction(function () use ($contact, $conversation, $body, $providerId, $files, $attachmentSpecs, $data) {
            $conversation ??= SupportConversation::create([
                'public_id' => (string) Str::uuid(),
                'contact_id' => $contact->id,
                'status' => 'open',
                'mode' => 'ai',
                'last_message_at' => now(),
            ]);

            $message = SupportMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'sender_type' => 'customer',
                'body' => $body,
                'provider_message_id' => $providerId,
                'delivery_status' => 'received',
                'metadata' => ['channel' => 'platform', 'num_media' => count($files)],
                'sent_at' => now(),
            ]);

            foreach ($files as $index => $file) {
                $spec = $attachmentSpecs[$index];
                $extension = $file->guessExtension() ?: ($spec['kind'] === 'image' ? 'jpg' : 'webm');
                $name = Str::uuid().'.'.$extension;
                $path = $file->storeAs('support/'.now()->format('Y/m'), $name, 'local');
                if (! $path) {
                    throw new \RuntimeException('Could not store support attachment.');
                }
                SupportMessageAttachment::create([
                    'public_id' => (string) Str::uuid(),
                    'support_message_id' => $message->id,
                    'kind' => $spec['kind'],
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                    'mime_type' => $spec['mime'],
                    'size_bytes' => $file->getSize(),
                    'duration_seconds' => $spec['kind'] === 'audio' ? ($data['audioDuration'] ?? null) : null,
                    'status' => $spec['kind'] === 'image' ? 'ready' : 'uploaded',
                ]);
            }
            $conversation->update(['last_message_at' => now()]);

            return $message;
        });

        $message->load('conversation', 'attachments');
        $humanOwned = $message->conversation->mode === 'human';
        $processedImmediately = ! $humanOwned && $message->attachments->isEmpty() && (bool) config('support.platform_sync', true);
        if ($humanOwned) {
            // The assigned admin will respond; never start an AI job during takeover.
        } elseif ($processedImmediately) {
            $this->processImmediately($message->id);
        } else {
            ProcessSupportMessage::dispatch($message->id);
        }

        $conversation = $message->conversation->fresh();

        return response()->json([
            'conversation' => $this->serializeConversation($conversation),
            'processing' => $humanOwned ? 'human' : ($processedImmediately ? 'complete' : 'queued'),
        ], $processedImmediately ? 200 : 202);
    }

    public function attachment(Request $request, SupportMessageAttachment $attachment): StreamedResponse
    {
        $attachment->loadMissing('message.conversation.contact');
        abort_unless($attachment->message?->conversation?->contact?->user_id === $request->user()->id, 403);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function latestConversation(Request $request): ?SupportConversation
    {
        $contact = SupportContact::where('channel', 'platform')
            ->where('external_id', 'user:'.$request->user()->id)
            ->first();

        return $contact?->conversations()->latest('last_message_at')->latest('id')->first();
    }

    private function serializeConversation(SupportConversation $conversation): array
    {
        $messages = $conversation->messages()->with('attachments')->latest('id')->limit(100)->get()->reverse()->values();

        return [
            'id' => $conversation->public_id,
            'status' => $conversation->status,
            'mode' => $conversation->mode,
            'waitingForReply' => $conversation->status !== 'resolved'
                && $messages->last()?->direction === 'inbound',
            'messages' => $messages->map(fn (SupportMessage $message) => [
                'id' => $message->id,
                'sender' => $message->direction === 'inbound' ? 'user' : 'support',
                'senderType' => $message->sender_type,
                'body' => $message->body,
                'sentAt' => ($message->sent_at ?? $message->created_at)?->toISOString(),
                'attachments' => $message->attachments->map(fn (SupportMessageAttachment $attachment) => [
                    'id' => $attachment->public_id,
                    'kind' => $attachment->kind,
                    'mimeType' => $attachment->mime_type,
                    'size' => $attachment->size_bytes,
                    'duration' => $attachment->duration_seconds,
                    'status' => $attachment->status,
                    'transcript' => $attachment->transcript,
                    'url' => '/api/support/chat/attachments/'.$attachment->public_id,
                ])->values(),
            ])->values(),
        ];
    }

    private function validateAttachments(array $files, ?int $duration): array
    {
        $imageMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $audioMimes = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/ogg', 'audio/webm', 'audio/wav', 'audio/x-wav', 'video/webm'];
        $specs = [];
        $audioCount = 0;
        foreach ($files as $file) {
            $mime = strtolower((string) $file->getMimeType());
            if (in_array($mime, $imageMimes, true)) {
                if ($file->getSize() > ((int) config('support.max_image_kilobytes') * 1024)) {
                    abort(422, 'Each image must be 8 MB or smaller.');
                }
                $specs[] = ['kind' => 'image', 'mime' => $mime];
            } elseif (in_array($mime, $audioMimes, true)) {
                $audioCount++;
                if ($file->getSize() > ((int) config('support.max_audio_kilobytes') * 1024)) {
                    abort(422, 'Voice notes must be 10 MB or smaller.');
                }
                $specs[] = ['kind' => 'audio', 'mime' => $mime];
            } else {
                abort(422, 'Only PNG, JPEG, WEBP images and supported voice-note audio are allowed.');
            }
        }
        if ($audioCount > 1 || ($audioCount === 1 && count($files) > 1)) {
            abort(422, 'Send either one voice note or up to three images in a message.');
        }
        if ($audioCount === 1 && $duration !== null && $duration > 120) {
            abort(422, 'Voice notes must be two minutes or shorter.');
        }

        return $specs;
    }

    private function processImmediately(int $messageId): void
    {
        $keys = [
            'support.openai_budget_seconds',
            'support.openai_max_rounds',
            'services.openai.timeout',
            'services.openai.max_attempts',
        ];
        $original = collect($keys)->mapWithKeys(fn ($key) => [$key => config($key)])->all();
        $budget = (int) config('support.platform_sync_budget_seconds', 12);

        config()->set([
            'support.openai_budget_seconds' => $budget,
            'support.openai_max_rounds' => min(2, (int) config('support.openai_max_rounds', 3)),
            'services.openai.timeout' => min($budget, (int) config('services.openai.timeout', 20)),
            'services.openai.max_attempts' => 1,
        ]);

        try {
            dispatch_sync(new ProcessSupportMessage($messageId));
        } finally {
            config()->set($original);
        }
    }
}
