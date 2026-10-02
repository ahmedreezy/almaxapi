<?php

namespace App\Jobs;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Services\Support\OpenAiSupportService;
use App\Services\Support\SupportQuotaService;
use App\Services\Support\TwilioWhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSupportMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('support');
    }

    public function handle(
        OpenAiSupportService $agent,
        SupportQuotaService $quota,
        TwilioWhatsAppService $twilio,
    ): void {
        $incoming = SupportMessage::with('conversation.contact')->find($this->messageId);
        if (! $incoming || $incoming->direction !== 'inbound') {
            return;
        }

        if ($incoming->conversation->messages()
            ->where('direction', 'outbound')
            ->where('metadata->in_reply_to', $incoming->id)
            ->exists()) {
            return;
        }

        $conversation = $incoming->conversation;
        $contact = $conversation->contact;
        if (! $contact || $contact->blocked || $conversation->status === 'resolved') {
            return;
        }

        if ($conversation->mode !== 'ai' || in_array($conversation->status, ['waiting_human', 'human'], true)) {
            $conversation->update([
                'mode' => 'ai',
                'status' => 'open',
                'assigned_admin_id' => null,
                'human_requested_at' => null,
            ]);
        }

        if ((int) data_get($incoming->metadata, 'num_media', 0) > 0 && trim($incoming->body) === '') {
            $this->sendOperationalMessage($conversation, $twilio, (string) config('support.text_only_message'));

            return;
        }

        if (! $quota->reserve($contact)) {
            if ($quota->claimLimitNotice($contact)) {
                $this->sendOperationalMessage($conversation, $twilio, (string) config('support.limit_message'));
            }

            return;
        }

        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        try {
            $result = $agent->respond($conversation, $incoming);
            $usage = $result;

            $conversation->refresh();
            if ($conversation->status === 'resolved') {
                $quota->release($contact, $result['input_tokens'], $result['output_tokens']);

                return;
            }

            $conversation->update([
                'category' => $result['category'],
                'sentiment' => $result['sentiment'],
                'priority' => $result['priority'],
                'summary' => mb_substr($result['summary'], 0, 2000),
                'mode' => 'ai',
                'status' => 'open',
                'assigned_admin_id' => null,
                'human_requested_at' => null,
                'resolved_at' => $result['resolved'] ? now() : null,
            ]);

            if ($result['resolved']) {
                $conversation->update(['status' => 'resolved']);
            }

            $reply = trim($result['reply']);
            $isFirstReply = ! $conversation->messages()->where('direction', 'outbound')->exists();
            $greeting = (string) config('support.greeting');
            if ($isFirstReply && ! str_starts_with($reply, $greeting)) {
                $reply = $greeting."\n\n".$reply;
            }

            $sent = $this->deliver($conversation, $twilio, $reply);
            SupportMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'sender_type' => 'ai',
                'body' => $reply,
                'provider_message_id' => $sent['sid'] ?: null,
                'delivery_status' => $sent['status'],
                'metadata' => ['action' => $result['action'], 'in_reply_to' => $incoming->id],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'model' => $result['model'],
                'sent_at' => now(),
            ]);
            $conversation->update(['last_message_at' => now()]);
            $quota->consume($contact, $result['input_tokens'], $result['output_tokens']);
        } catch (Throwable $e) {
            $quota->release($contact, (int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0));
            $conversation->update([
                'mode' => 'ai',
                'status' => 'open',
                'priority' => 'high',
                'assigned_admin_id' => null,
                'human_requested_at' => null,
            ]);
            Log::error('Support message processing failed', [
                'message_id' => $incoming->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            try {
                $this->sendOperationalMessage($conversation, $twilio, (string) config('support.fallback_message'));
            } catch (Throwable $sendError) {
                Log::error('Support fallback send failed', ['error' => $sendError->getMessage()]);
                throw $e;
            }
        }
    }

    private function sendOperationalMessage(
        SupportConversation $conversation,
        TwilioWhatsAppService $twilio,
        string $body
    ): void {
        $contact = $conversation->contact;
        $greeting = (string) config('support.greeting');
        if (! $conversation->messages()->where('direction', 'outbound')->exists()
            && ! str_starts_with($body, $greeting)) {
            $body = $greeting."\n\n".$body;
        }

        $sent = $this->deliver($conversation, $twilio, $body);
        SupportMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'sender_type' => 'system',
            'body' => $body,
            'provider_message_id' => $sent['sid'] ?: null,
            'delivery_status' => $sent['status'],
            'metadata' => ['in_reply_to' => $conversation->messages()->where('direction', 'inbound')->latest('id')->value('id')],
            'sent_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);
    }

    /** @return array{sid:string,status:string} */
    private function deliver(
        SupportConversation $conversation,
        TwilioWhatsAppService $twilio,
        string $body
    ): array {
        if ($conversation->contact->channel === 'platform') {
            return ['sid' => '', 'status' => 'delivered'];
        }

        return $twilio->sendText(
            $conversation->contact->phone ?: $conversation->contact->external_id,
            $body
        );
    }
}
