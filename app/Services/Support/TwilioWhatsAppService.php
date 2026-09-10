<?php

namespace App\Services\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioWhatsAppService
{
    public function validateSignature(string $signature, string $url, array $parameters): bool
    {
        $token = (string) config('services.twilio.auth_token');
        if ($token === '' || $signature === '') {
            return false;
        }

        ksort($parameters, SORT_STRING);
        $payload = $url;
        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $payload .= $key.(string) $item;
                }
            } else {
                $payload .= $key.(string) $value;
            }
        }

        $expected = base64_encode(hash_hmac('sha1', $payload, $token, true));

        return hash_equals($expected, $signature);
    }

    /** @return array{sid:string,status:string,raw:array} */
    public function sendText(string $to, string $body): array
    {
        $accountSid = (string) config('services.twilio.account_sid');
        $authToken = (string) config('services.twilio.auth_token');
        $from = $this->whatsappAddress((string) config('services.twilio.whatsapp_from'));

        if ($accountSid === '' || $authToken === '' || $from === 'whatsapp:') {
            throw new RuntimeException('Twilio WhatsApp credentials are not configured.');
        }

        $form = [
            'From' => $from,
            'To' => $this->whatsappAddress($to),
            'Body' => mb_substr(trim($body), 0, 1500),
        ];
        $statusCallback = (string) config('services.twilio.status_webhook_url');
        if ($statusCallback !== '') {
            $form['StatusCallback'] = $statusCallback;
        }

        $response = Http::asForm()
            ->withBasicAuth($accountSid, $authToken)
            ->timeout(20)
            ->retry(2, 300)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", $form);

        $this->throwForFailure($response);
        $data = $response->json();

        return [
            'sid' => (string) ($data['sid'] ?? ''),
            'status' => (string) ($data['status'] ?? 'queued'),
            'raw' => is_array($data) ? $data : [],
        ];
    }

    private function whatsappAddress(string $number): string
    {
        $number = trim($number);

        return str_starts_with(strtolower($number), 'whatsapp:') ? $number : 'whatsapp:'.$number;
    }

    private function throwForFailure(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message') ?: $response->body();
        throw new RuntimeException('Twilio send failed: '.mb_substr((string) $message, 0, 300));
    }
}
