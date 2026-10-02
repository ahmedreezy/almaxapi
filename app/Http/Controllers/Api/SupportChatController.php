<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSupportMessage;
use App\Models\SupportContact;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'body' => ['required', 'string', 'max:2000'],
            'clientMessageId' => ['required', 'uuid'],
        ]);
        $body = trim($data['body']);
        if ($body === '') {
            return response()->json(['message' => 'Please enter a message.'], 422);
        }

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

        if ($conversation) {
            if ($conversation->mode !== 'ai' || in_array($conversation->status, ['waiting_human', 'human'], true)) {
                $conversation->update([
                    'mode' => 'ai',
                    'status' => 'open',
                    'assigned_admin_id' => null,
                    'human_requested_at' => null,
                ]);
            }

            $latest = $conversation->messages()->latest('id')->first();
            if ($latest?->direction === 'inbound') {
                return response()->json([
                    'message' => 'Please wait while we answer your previous message.',
                    'conversation' => $this->serializeConversation($conversation),
                ], 409);
            }
        }

        $message = DB::transaction(function () use ($contact, $conversation, $body, $providerId) {
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
                'metadata' => ['channel' => 'platform'],
                'sent_at' => now(),
            ]);
            $conversation->update(['last_message_at' => now()]);

            return $message;
        });

        ProcessSupportMessage::dispatch($message->id);

        return response()->json([
            'conversation' => $this->serializeConversation($message->conversation),
        ], 202);
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
        $messages = $conversation->messages()->latest('id')->limit(100)->get()->reverse()->values();

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
            ])->values(),
        ];
    }
}
