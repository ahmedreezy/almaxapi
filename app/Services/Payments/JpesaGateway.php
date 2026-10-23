<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Services\MobileMoneyService;
use Illuminate\Http\Request;

class JpesaGateway implements PaymentGateway
{
    public function __construct(private readonly MobileMoneyService $service = new MobileMoneyService) {}

    public function name(): string
    {
        return 'jpesa';
    }

    public function capabilities(): array
    {
        return ['collections' => true, 'payouts' => true];
    }

    public function readiness(bool $live = false): array
    {
        return $this->service->readiness();
    }

    public function initiateCollection(string $phone, float $amount, string $reference, string $paymentMethod): array
    {
        $result = $this->service->initiateSTKPush($phone, $amount, $reference, $paymentMethod);
        $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
        $providerId = $raw['tid'] ?? $raw['transaction_id'] ?? $raw['txn_id'] ?? null;

        return $result + [
            'provider_request_id' => $providerId,
            'transaction_id' => $providerId,
            'final' => false,
        ];
    }

    public function queryCollection(Payment $payment, ?string $manualReference = null): array
    {
        $reference = $manualReference ?: $payment->transaction_id ?: $payment->provider_request_id;
        if (! $reference || $reference === $payment->payment_reference) {
            return ['success' => false, 'status' => 'pending', 'message' => 'JPesa transaction ID is not available.'];
        }

        $result = $this->service->queryTransaction((string) $reference);

        return $result + [
            'status' => ($result['success'] ?? false) ? 'success' : 'unknown',
            'transaction_id' => $reference,
        ];
    }

    public function initiatePayout(int $amount, string $reference, string $description, string $mobile): array
    {
        return $this->service->initiateConfiguredPayout($amount, $reference, $description) + ['final' => true];
    }

    public function verifyWebhook(Request $request): bool
    {
        $signature = $request->header('X-Signature', $request->header('X-Webhook-Signature', ''));

        return $this->service->validateWebhookSignature($request->getContent(), $signature);
    }

    public function parseWebhook(Request $request): array
    {
        return $this->service->parseWebhookPayload($request->all());
    }
}
