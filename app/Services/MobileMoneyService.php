<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mobile Money STK Push abstraction.
 *
 * This service is a structured placeholder ready for a specific provider.
 * To integrate a real gateway, set these .env variables and implement
 * the provider-specific HTTP calls below:
 *
 *   MOBILE_MONEY_API_URL=https://api.yourprovider.com
 *   MOBILE_MONEY_API_KEY=your_api_key
 *   MOBILE_MONEY_API_SECRET=your_api_secret
 *   MOBILE_MONEY_WEBHOOK_SECRET=your_webhook_hmac_secret
 *   MOBILE_MONEY_CALLBACK_URL=https://yourdomain.com/api/payments/webhook
 */
class MobileMoneyService
{
    private string $apiUrl;
    private string $apiKey;
    private string $apiSecret;
    private string $callbackUrl;
    private bool $agentCommissionEnabled;
    private float $agentCommissionRatio;
    private string $agentCommissionRecipientType;
    private string $agentCommissionRecipientEmail;
    private string $agentCommissionRecipientMobile;
    private string $agentCommissionTransferAction;
    private string $agentCommissionTransferPt;
    private string $agentCommissionCurrency;

    public function __construct()
    {
        $this->apiUrl      = (string) config('services.mobile_money.api_url', '');
        $this->apiKey      = (string) config('services.mobile_money.api_key', '');
        $this->apiSecret   = (string) config('services.mobile_money.api_secret', '');
        $this->callbackUrl = (string) config('services.mobile_money.callback_url', '');

        $commission = config('services.mobile_money.agent_commission', []);
        $this->agentCommissionEnabled       = filter_var($commission['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $this->agentCommissionRatio         = (float) ($commission['ratio'] ?? 0.1);
        $this->agentCommissionRecipientType = strtolower((string) ($commission['recipient_type'] ?? 'email'));
        $this->agentCommissionRecipientEmail = trim((string) ($commission['recipient_email'] ?? ''));
        $this->agentCommissionRecipientMobile = trim((string) ($commission['recipient_mobile'] ?? ''));
        $this->agentCommissionTransferAction = trim((string) ($commission['transfer_action'] ?? 'debit'));
        $this->agentCommissionTransferPt     = trim((string) ($commission['transfer_pt'] ?? 'gwallet'));
        $this->agentCommissionCurrency       = strtoupper(trim((string) ($commission['currency'] ?? 'UGX')));
    }

    /**
     * Initiate an STK push (phone prompt) to collect payment.
     *
     * @param  string $phone           Customer phone number (e.g. "0772000000")
     * @param  float  $amount          Amount to charge in UGX
     * @param  string $reference       Unique internal reference (subscription ID + timestamp)
     * @param  string $paymentMethod   'mtn' | 'airtel'
     * @return array{success: bool, reference: string, message: string, raw?: array}
     */
    public function initiateSTKPush(string $phone, float $amount, string $reference, string $paymentMethod): array
    {
        // While the provider is not yet configured, return a pending state
        // so the subscription record is created and can be activated manually
        // or via webhook once credentials are plugged in.
        if (empty($this->apiUrl) || empty($this->apiKey)) {
            Log::info('MobileMoneyService: provider not configured — subscription created as pending.', [
                'phone'     => $phone,
                'amount'    => $amount,
                'reference' => $reference,
                'method'    => $paymentMethod,
            ]);

            return [
                'success'   => false,
                'reference' => $reference,
                'message'   => 'Payment provider not yet configured. Your request has been recorded and will be processed manually.',
                'pending'   => true,
            ];
        }

        $normalizedPhone = $this->normalizePhone($phone);
        $apiUrl = $this->apiUrl;
        if (str_contains($apiUrl, 'my.jpesa.com') && str_contains($apiUrl, '/api/collect')) {
            $apiUrl = 'https://my.jpesa.com/api/';
        }

        $xml = $this->buildJpesaXml([
            '_key_'       => $this->apiKey,
            'cmd'         => 'account',
            'action'      => 'credit',
            'pt'          => 'mm',
            'mobile'      => $normalizedPhone,
            'amount'      => (string) ((int) round($amount)),
            'callback'    => $this->callbackUrl,
            'tx'          => $reference,
            'description' => 'Almax VIP Subscription',
        ]);

        try {
            $response = Http::withHeaders([
                    'Content-Type' => 'text/xml',
                    'Accept'       => 'application/json',
                ])
                ->timeout(30)
                ->withBody($xml, 'text/xml')
                ->post($apiUrl);

            $json = $this->decodeProviderResponse((string) $response->body());
            $status = strtolower((string) (
                ($json['status'] ?? null)
                ?? ($json['payment_status'] ?? null)
                ?? ($json['result'] ?? null)
                ?? ''
            ));
            $apiStatus = strtolower((string) (($json['api_status'] ?? $json['apiStatus'] ?? '')));
            $messageText = strtolower((string) ($json['message'] ?? $json['msg'] ?? ''));

            $looksAccepted = in_array($status, ['success', 'successful', 'accepted', 'pending', 'processing', 'queued'], true);
            $looksError = in_array($status, ['error', 'failed', 'failure', 'declined', 'rejected', 'cancelled'], true)
                || in_array($apiStatus, ['error', 'failed', 'failure'], true)
                || str_contains($messageText, 'invalid')
                || str_contains($messageText, 'missing api key')
                || str_contains($messageText, 'unauthor');

            $success = $response->successful() && ! $looksError && ($looksAccepted || ($status === '' && $apiStatus !== 'error'));

            if ($success) {
                return [
                    'success'   => true,
                    'reference' => $reference,
                    'message'   => $json['message'] ?? $json['msg'] ?? 'Payment request sent to your phone.',
                    'raw'       => is_array($json) ? $json : ['body' => $response->body()],
                ];
            }

            Log::warning('MobileMoneyService: STK push rejected by provider.', [
                'status_code' => $response->status(),
                'status'      => $status,
                'reference'   => $reference,
                'endpoint'    => $apiUrl,
                'body'        => $response->body(),
            ]);

            return [
                'success'   => false,
                'reference' => $reference,
                'message'   => (is_array($json) ? ($json['message'] ?? null) : null)
                    ?? 'Payment initiation failed at provider.',
                'raw'       => is_array($json) ? $json : ['body' => $response->body()],
            ];
        } catch (\Throwable $e) {
            Log::error('MobileMoneyService: STK push request failed.', [
                'reference' => $reference,
                'endpoint'  => $apiUrl,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success'   => false,
                'reference' => $reference,
                'message'   => 'Payment request could not be sent. Please try again shortly.',
            ];
        }
    }

    /**
     * Validate a webhook payload signature from the payment provider.
     *
     * @param  string $payload   Raw request body (JSON string)
     * @param  string $signature Signature header from the provider
     * @return bool
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('services.mobile_money.webhook_secret', '');

        if (empty($secret)) {
            // No secret configured — accept all webhooks (development only)
            Log::warning('MobileMoneyService: webhook_secret not set; skipping signature validation.');
            return true;
        }

        // ── STUB: Replace with provider-specific HMAC verification ────────
        //
        // Common pattern (HMAC-SHA256):
        // $expected = hash_hmac('sha256', $payload, $secret);
        // return hash_equals($expected, $signature);
        //
        // ── END STUB ───────────────────────────────────────────────────────

        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Parse a webhook payload into a normalised structure.
     *
     * @param  array $data Decoded JSON body from the provider
     * @return array{reference: string, status: string, transaction_id: string|null}
     */
    public function parseWebhookPayload(array $data): array
    {
        $status = strtolower((string) (
            $data['status']
            ?? $data['payment_status']
            ?? $data['result']
            ?? $data['state']
            ?? 'unknown'
        ));

        if (in_array($status, ['ok', 'success', 'successful', 'completed'], true)) {
            $status = 'success';
        } elseif (in_array($status, ['approved'], true)) {
            $status = 'success';
        } elseif (in_array($status, ['failed', 'failure', 'cancelled', 'declined', 'rejected', 'error'], true)) {
            $status = 'failed';
        }

        return [
            'reference'      => $data['reference']
                ?? $data['external_reference']
                ?? $data['external_ref']
                ?? $data['merchant_reference']
                ?? $data['merchant_ref']
                ?? $data['order_reference']
                ?? $data['memo']
                ?? $data['tx']
                ?? $data['ref']
                ?? '',
            'status'         => $status,
            'transaction_id' => $data['transaction_id']
                ?? $data['txn_id']
                ?? $data['txnid']
                ?? $data['trx_id']
                ?? $data['tr_id']
                ?? $data['provider_txn_id']
                ?? $data['tid']
                ?? null,
        ];
    }

    /**
     * Query a transaction's status by its provider transaction ID.
     *
     * Used to verify a transaction ID that the user submits manually via the
     * "Already paid?" flow, both in local dev and production.
     * Credentials are taken from the same .env keys as the STK push:
     *   JPESA_API_URL  → https://my.jpesa.com/api/
     *   JPESA_API_KEY  → your key
     *
     * @param  string $txnId  Transaction ID from the user's Airtel Money SMS
     * @return array{success: bool, message: string, txn_id: string, raw?: array}
     */
    public function queryTransaction(string $txnId): array
    {
        if (empty($this->apiUrl) || empty($this->apiKey)) {
            Log::warning('MobileMoneyService: cannot query transaction — provider not configured.', [
                'txn_id' => $txnId,
            ]);

            return [
                'success' => false,
                'message' => 'Payment verification service is not available right now. Please contact support with your transaction ID.',
                'txn_id'  => $txnId,
            ];
        }

        // Sanitise — prevent injection into the XML payload
        $safeTxnId = preg_replace('/[^A-Za-z0-9\-_.]/', '', $txnId);
        if ($safeTxnId !== $txnId || empty($safeTxnId)) {
            return [
                'success' => false,
                'message' => 'Invalid transaction ID format. Please copy it exactly from your SMS.',
                'txn_id'  => $txnId,
            ];
        }

        $apiUrl = $this->apiUrl;
        if (str_contains($apiUrl, 'my.jpesa.com') && str_contains($apiUrl, '/api/collect')) {
            $apiUrl = 'https://my.jpesa.com/api/';
        }

        $xml = $this->buildJpesaXml([
            '_key_'  => $this->apiKey,
            'cmd'    => 'account',
            'action' => 'query',
            'tid'    => $safeTxnId,
        ]);

        try {
            $response = Http::withHeaders([
                    'Content-Type' => 'text/xml',
                    'Accept'       => 'application/json',
                ])
                ->timeout(20)
                ->withBody($xml, 'text/xml')
                ->post($apiUrl);

            $json = $this->decodeProviderResponse((string) $response->body());

            $status = strtolower((string) (
                $json['status']         ??
                $json['payment_status'] ??
                $json['result']         ??
                $json['state']          ??
                ''
            ));

            if (in_array($status, ['success', 'successful', 'completed', 'ok', 'approved'], true)) {
                Log::info('MobileMoneyService: transaction verified via query.', [
                    'txn_id' => $txnId,
                    'status' => $status,
                ]);

                return [
                    'success' => true,
                    'message' => 'Transaction verified successfully.',
                    'txn_id'  => $txnId,
                    'raw'     => $json,
                ];
            }

            if (in_array($status, ['failed', 'failure', 'declined', 'rejected', 'error', 'cancelled'], true)) {
                return [
                    'success' => false,
                    'message' => 'This transaction was not successful. Please check your Airtel Money SMS and try again.',
                    'txn_id'  => $txnId,
                    'raw'     => $json,
                ];
            }

            // Unknown/ambiguous — log and refuse to activate
            Log::warning('MobileMoneyService: ambiguous queryTransaction response — not activating.', [
                'txn_id' => $txnId,
                'status' => $status,
                'body'   => $response->body(),
            ]);

            return [
                'success' => false,
                'message' => 'Could not confirm transaction status. Please contact support and quote transaction ID: ' . $txnId,
                'txn_id'  => $txnId,
                'raw'     => $json,
            ];

        } catch (\Throwable $e) {
            Log::error('MobileMoneyService: queryTransaction request failed.', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Could not reach payment verification service. Please try again shortly.',
                'txn_id'  => $txnId,
            ];
        }
    }

    /**
     * Send the configured agent's commission for a confirmed payment.
     *
     * The method first claims the payment row by setting
     * agent_commission_status=processing. That keeps repeated JPesa callbacks
     * from transferring the same commission twice.
     *
     * @return array{success: bool, skipped?: bool, message: string, raw?: array}
     */
    public function processAgentCommission(Payment $payment, string $source): array
    {
        if (! $this->agentCommissionEnabled || $this->agentCommissionRatio <= 0) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Agent commission is disabled.',
            ];
        }

        $payment->refresh();

        if ($payment->status !== 'confirmed') {
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Payment is not confirmed.',
            ];
        }

        $commissionAmount = (int) round(((float) $payment->amount) * $this->agentCommissionRatio);
        if ($commissionAmount < 1) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Commission amount rounded below 1.',
            ];
        }

        $recipient = $this->agentCommissionRecipient();
        if ($recipient['value'] === '') {
            Log::warning('MobileMoneyService: agent commission recipient not configured.', [
                'payment_id' => $payment->id,
                'source'     => $source,
            ]);

            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Agent commission recipient is not configured.',
            ];
        }

        $reference = $payment->agent_commission_reference ?: $this->buildAgentCommissionReference($payment);

        $claimed = Payment::whereKey($payment->id)
            ->where('status', 'confirmed')
            ->where(function ($query) {
                $query->whereNull('agent_commission_status')
                    ->orWhere('agent_commission_status', '')
                    ->orWhereIn('agent_commission_status', ['failed', 'failure', 'error', 'pending', 'queued']);
            })
            ->update([
                'agent_commission_amount'       => $commissionAmount,
                'agent_commission_ratio'        => $this->agentCommissionRatio,
                'agent_commission_status'       => 'processing',
                'agent_commission_reference'    => $reference,
                'agent_commission_recipient'    => $recipient['display'],
                'agent_commission_error'        => null,
                'agent_commission_processed_at' => null,
            ]);

        if ($claimed === 0) {
            Log::info('MobileMoneyService: agent commission already handled or in progress.', [
                'payment_id' => $payment->id,
                'source'     => $source,
                'status'     => $payment->agent_commission_status,
            ]);

            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Agent commission already handled or in progress.',
            ];
        }

        $description = 'Almax agent commission for payment ' . ($payment->payment_reference ?: $payment->id);
        $result = $this->sendJpesaInternalTransfer(
            amount: $commissionAmount,
            reference: $reference,
            description: $description,
            recipient: $recipient
        );

        $payment = $payment->fresh();

        if ($result['success']) {
            $payment->update([
                'agent_commission_status'         => 'sent',
                'agent_commission_transaction_id' => $result['transaction_id'] ?? null,
                'agent_commission_error'          => null,
                'agent_commission_processed_at'   => now(),
            ]);

            Log::info('MobileMoneyService: agent commission sent.', [
                'payment_id' => $payment->id,
                'amount'     => $commissionAmount,
                'reference'  => $reference,
                'source'     => $source,
            ]);

            return [
                'success' => true,
                'message' => 'Agent commission sent.',
                'raw'     => $result['raw'] ?? [],
            ];
        }

        $payment->update([
            'agent_commission_status'       => 'failed',
            'agent_commission_error'        => substr($result['message'] ?? 'Agent commission transfer failed.', 0, 1000),
            'agent_commission_processed_at' => now(),
        ]);

        Log::warning('MobileMoneyService: agent commission transfer failed.', [
            'payment_id' => $payment->id,
            'amount'     => $commissionAmount,
            'reference'  => $reference,
            'source'     => $source,
            'message'    => $result['message'] ?? 'unknown',
        ]);

        return [
            'success' => false,
            'message' => $result['message'] ?? 'Agent commission transfer failed.',
            'raw'     => $result['raw'] ?? [],
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone ?? '') ?? '';

        if (str_starts_with($digits, '256')) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '256' . substr($digits, 1);
        }

        return $digits;
    }

    /**
     * @param  array{field: string, value: string, display: string} $recipient
     * @return array{success: bool, message: string, transaction_id?: string|null, raw?: array}
     */
    private function sendJpesaInternalTransfer(int $amount, string $reference, string $description, array $recipient): array
    {
        if (empty($this->apiUrl) || empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'JPesa source account is not configured.',
            ];
        }

        $fields = [
            '_key_'       => $this->apiKey,
            'cmd'         => 'account',
            'action'      => $this->agentCommissionTransferAction ?: 'debit',
        ];

        if ($this->agentCommissionTransferPt !== '') {
            $fields['pt'] = $this->agentCommissionTransferPt;
        }

        $fields[$recipient['field']] = $recipient['value'];
        $fields['cur'] = $this->agentCommissionCurrency ?: 'UGX';
        $fields['amount'] = (string) $amount;
        $fields['callback'] = $this->callbackUrl;
        $fields['tx'] = $reference;
        $fields['description'] = $description;

        $apiUrl = $this->jpesaApiEndpoint();
        $xml = $this->buildJpesaXml($fields);

        try {
            $response = Http::withHeaders([
                    'Content-Type' => 'text/xml',
                    'Accept'       => 'application/json',
                ])
                ->timeout(30)
                ->withBody($xml, 'text/xml')
                ->post($apiUrl);

            $rawBody = (string) $response->body();
            $json = $this->decodeProviderResponse($rawBody);

            $success = $this->providerResponseWasAccepted($response, $json, $rawBody);
            $transactionId = $json['tid']
                ?? $json['transaction_id']
                ?? $json['txn_id']
                ?? $json['trx_id']
                ?? null;

            return [
                'success'        => $success,
                'message'        => $this->providerMessage($json) ?? ($success ? 'Transfer accepted.' : 'Transfer rejected by provider.'),
                'transaction_id' => $transactionId,
                'raw'            => $json ?: ['body' => $rawBody],
            ];
        } catch (\Throwable $e) {
            Log::error('MobileMoneyService: JPesa internal transfer request failed.', [
                'reference' => $reference,
                'endpoint'  => $apiUrl,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Could not send JPesa internal transfer. Please retry from the payment record.',
            ];
        }
    }

    /**
     * @return array{field: string, value: string, display: string}
     */
    private function agentCommissionRecipient(): array
    {
        $email = $this->agentCommissionRecipientEmail;
        $mobile = $this->agentCommissionRecipientMobile !== ''
            ? $this->normalizePhone($this->agentCommissionRecipientMobile)
            : '';

        $useMobile = $this->agentCommissionRecipientType === 'mobile';
        if ($useMobile && $mobile !== '') {
            return [
                'field'   => 'mobile',
                'value'   => $mobile,
                'display' => $email !== '' ? "{$email} ({$mobile})" : $mobile,
            ];
        }

        if ($email !== '') {
            return [
                'field'   => 'business',
                'value'   => $email,
                'display' => $mobile !== '' ? "{$email} ({$mobile})" : $email,
            ];
        }

        return [
            'field'   => 'mobile',
            'value'   => $mobile,
            'display' => $mobile,
        ];
    }

    private function buildAgentCommissionReference(Payment $payment): string
    {
        $hash = substr(sha1(($payment->payment_reference ?? '') . '|' . $payment->id), 0, 10);

        return 'ALX-COM-' . $payment->id . '-' . $hash;
    }

    private function jpesaApiEndpoint(): string
    {
        if (str_contains($this->apiUrl, 'my.jpesa.com') && str_contains($this->apiUrl, '/api/collect')) {
            return 'https://my.jpesa.com/api/';
        }

        return $this->apiUrl;
    }

    private function decodeProviderResponse(string $body): array
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }

        $serialized = @unserialize($body, ['allowed_classes' => false]);
        if (is_array($serialized)) {
            return $serialized;
        }

        parse_str($body, $parsed);
        if (is_array($parsed) && count($parsed) > 0 && implode('', array_keys($parsed)) !== $body) {
            return $parsed;
        }

        return [];
    }

    private function providerMessage(array $json): ?string
    {
        $message = $json['message']
            ?? $json['msg']
            ?? $json['error']
            ?? null;

        return $message !== null ? (string) $message : null;
    }

    private function providerResponseWasAccepted($response, array $json, string $body = ''): bool
    {
        $status = strtolower((string) (
            ($json['status'] ?? null)
            ?? ($json['payment_status'] ?? null)
            ?? ($json['result'] ?? null)
            ?? ($json['state'] ?? null)
            ?? ''
        ));
        $apiStatus = strtolower((string) (($json['api_status'] ?? $json['apiStatus'] ?? '')));
        $messageText = strtolower((string) ($this->providerMessage($json) ?? ''));
        $bodyText = strtolower($body);

        $looksAccepted = in_array($status, ['success', 'successful', 'accepted', 'pending', 'processing', 'queued', 'approved', 'ok', 'completed'], true)
            || in_array($apiStatus, ['success', 'successful', 'accepted', 'ok'], true);
        $looksError = in_array($status, ['error', 'failed', 'failure', 'declined', 'rejected', 'cancelled'], true)
            || in_array($apiStatus, ['error', 'failed', 'failure'], true)
            || str_contains($messageText, 'invalid')
            || str_contains($messageText, 'missing api key')
            || str_contains($messageText, 'unauthor')
            || str_contains($messageText, 'insufficient')
            || str_contains($messageText, 'not permitted')
            || str_contains($messageText, 'not allowed')
            || str_contains($bodyText, 'api_status";s:5:"error')
            || str_contains($bodyText, '"api_status":"error"')
            || str_contains($bodyText, 'not permitted to this api');

        return $response->successful() && ! $looksError && ($looksAccepted || (! empty($json) && $status === '' && $apiStatus !== 'error'));
    }

    private function buildJpesaXml(array $fields): string
    {
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n<g7bill>\n";
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'ISO-8859-1');
            $xml .= "  <{$key}>{$escaped}</{$key}>\n";
        }
        $xml .= '</g7bill>';

        return $xml;
    }
}
