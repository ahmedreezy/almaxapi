<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSupportMessage;
use App\Models\SupportContact;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\Support\PhoneNumberNormalizer;
use App\Services\Support\TwilioWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TwilioSupportWebhookController extends Controller
{
    public function inbound(
        Request $request,
        TwilioWhatsAppService $twilio,
        PhoneNumberNormalizer $phones
    ): Response {
        $parameters = $this->rawFormParameters($request);
        if (! $twilio->validateSignature(
            (string) $request->header('X-Twilio-Signature'),
            $this->signatureUrl($request, 'inbound_webhook_url'),
            $parameters
        )) {
            return response('Invalid Twilio signature.', 403);
        }

        $providerId = trim((string) ($parameters['MessageSid'] ?? ''));
        if ($providerId === '' || SupportMessage::where('provider_message_id', $providerId)->exists()) {
            return $this->emptyTwiMl();
        }

        $phone = $phones->normalize((string) ($parameters['From'] ?? ''));
        if ($phone === '') {
            return response('Missing sender.', 422);
        }

        $user = User::whereIn('phone', $phones->databaseCandidates($phone))->first();
        $contact = SupportContact::firstOrCreate(
            ['channel' => 'whatsapp', 'external_id' => $phone],
            ['phone' => $phone, 'user_id' => $user?->id]
        );
        $contact->fill([
            'phone' => $phone,
            'display_name' => mb_substr(trim((string) ($parameters['ProfileName'] ?? '')), 0, 200) ?: $contact->display_name,
            'user_id' => $contact->user_id ?: $user?->id,
        ])->save();

        $conversation = $contact->conversations()
            ->where('status', '!=', 'resolved')
            ->latest('id')
            ->first();
        if (! $conversation) {
            $conversation = SupportConversation::create([
                'public_id' => (string) Str::uuid(),
                'contact_id' => $contact->id,
                'status' => 'open',
                'mode' => 'ai',
                'last_message_at' => now(),
            ]);
        }

        $message = SupportMessage::firstOrCreate(
            ['provider_message_id' => $providerId],
            [
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'sender_type' => 'customer',
                'body' => mb_substr((string) ($parameters['Body'] ?? ''), 0, 5000),
                'delivery_status' => 'received',
                'metadata' => [
                    'num_media' => (int) ($parameters['NumMedia'] ?? 0),
                    'whatsapp_message_id' => $parameters['WaId'] ?? null,
                ],
                'sent_at' => now(),
            ]
        );
        if (! $message->wasRecentlyCreated) {
            return $this->emptyTwiMl();
        }

        $conversation->update(['last_message_at' => now()]);

        ProcessSupportMessage::dispatch($message->id);

        return $this->emptyTwiMl();
    }

    public function status(Request $request, TwilioWhatsAppService $twilio): Response
    {
        $parameters = $this->rawFormParameters($request);
        if (! $twilio->validateSignature(
            (string) $request->header('X-Twilio-Signature'),
            $this->signatureUrl($request, 'status_webhook_url'),
            $parameters
        )) {
            return response('Invalid Twilio signature.', 403);
        }

        $sid = (string) ($parameters['MessageSid'] ?? '');
        $status = (string) ($parameters['MessageStatus'] ?? $parameters['SmsStatus'] ?? 'unknown');
        SupportMessage::where('provider_message_id', $sid)->update([
            'delivery_status' => mb_substr($status, 0, 30),
            'updated_at' => now(),
        ]);

        return response('', 204);
    }

    private function rawFormParameters(Request $request): array
    {
        $parameters = [];
        parse_str($request->getContent(), $parameters);

        return $parameters ?: $request->all();
    }

    private function signatureUrl(Request $request, string $configKey): string
    {
        return (string) config("services.twilio.{$configKey}") ?: $request->fullUrl();
    }

    private function emptyTwiMl(): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200)
            ->header('Content-Type', 'text/xml');
    }
}
