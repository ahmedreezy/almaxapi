<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class AgentCommissionService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly WalletLedgerService $wallets,
    ) {}

    public function process(Payment $payment, string $source): array
    {
        $commission = config('services.mobile_money.agent_commission', []);
        $enabled = filter_var($commission['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $ratio = (float) ($commission['ratio'] ?? 0.2);
        $payment->refresh();
        if ($payment->status !== 'confirmed') {
            return ['success' => false, 'skipped' => true, 'message' => 'Payment is not confirmed.'];
        }

        $provider = $payment->provider ?: 'jpesa';
        $amount = $enabled && $ratio > 0
            ? (int) round(((float) $payment->amount) * $ratio)
            : 0;

        if (! $enabled || $ratio <= 0 || $amount < 1) {
            $this->wallets->accrueConfirmedPayment($payment, 0, false);

            return ['success' => false, 'skipped' => true, 'message' => 'Agent commission is disabled.'];
        }

        $reference = $payment->agent_commission_reference ?: $this->reference($payment);
        if ($provider === 'relworx') {
            $currentStatus = strtolower((string) $payment->agent_commission_status);
            if (in_array($currentStatus, ['sent', 'completed', 'processing'], true)) {
                $this->wallets->accrueConfirmedPayment($payment, $amount, false);

                return ['success' => false, 'skipped' => true, 'message' => 'Legacy Relworx commission is already settled or requires reconciliation.'];
            }

            $payment->update([
                'agent_commission_amount' => $amount,
                'agent_commission_ratio' => $ratio,
                'agent_commission_status' => 'accrued',
                'agent_commission_reference' => $reference,
                'agent_commission_provider' => 'relworx',
                'agent_commission_provider_request_id' => null,
                'agent_commission_transaction_id' => null,
                'agent_commission_error' => null,
                'agent_commission_processed_at' => now(),
            ]);
            $this->wallets->accrueConfirmedPayment($payment->fresh(), $amount, true);

            return [
                'success' => true,
                'accrued' => true,
                'final' => false,
                'message' => 'Relworx commission accrued for payout request.',
            ];
        }

        $mobile = trim((string) ($commission['mobile'] ?? $commission['recipient_mobile'] ?? ''));
        $jpesaRecipient = trim((string) ($commission['recipient_email'] ?? '')) !== '' || $mobile !== '';
        if (! $jpesaRecipient) {
            $this->wallets->accrueConfirmedPayment($payment, $amount, false);

            return ['success' => false, 'skipped' => true, 'message' => 'Commission recipient mobile is not configured.'];
        }
        $claimed = Payment::whereKey($payment->id)
            ->where('status', 'confirmed')
            ->where(function ($query) {
                $query->whereNull('agent_commission_status')
                    ->orWhere('agent_commission_status', '')
                    ->orWhereIn('agent_commission_status', ['failed', 'failure', 'error', 'pending', 'queued']);
            })
            ->update([
                'agent_commission_amount' => $amount,
                'agent_commission_ratio' => $ratio,
                'agent_commission_status' => 'processing',
                'agent_commission_reference' => $reference,
                'agent_commission_recipient' => $mobile,
                'agent_commission_provider' => $provider,
                'agent_commission_error' => null,
                'agent_commission_processed_at' => null,
            ]);

        if ($claimed === 0) {
            $this->wallets->accrueConfirmedPayment($payment, $amount, false);

            return ['success' => false, 'skipped' => true, 'message' => 'Agent commission already handled or in progress.'];
        }

        $this->wallets->accrueConfirmedPayment($payment->fresh(), $amount, false);

        $result = $this->gateways->gateway($provider)->initiatePayout(
            $amount,
            $reference,
            'Almax agent commission for payment '.($payment->payment_reference ?: $payment->id),
            $mobile
        );

        $payment = $payment->fresh();
        $requestId = $result['provider_request_id'] ?? $result['transaction_id'] ?? null;
        if ($result['success'] ?? false) {
            $isFinal = (bool) ($result['final'] ?? false);
            $payment->update([
                'agent_commission_status' => $isFinal ? 'sent' : 'processing',
                'agent_commission_provider_request_id' => $requestId,
                'agent_commission_transaction_id' => $isFinal ? $requestId : null,
                'agent_commission_error' => null,
                'agent_commission_processed_at' => $isFinal ? now() : null,
            ]);

            return $result + ['message' => $isFinal ? 'Agent commission sent.' : 'Agent commission payout accepted.'];
        }

        $uncertain = (bool) ($result['pending'] ?? false);
        $payment->update([
            'agent_commission_status' => $uncertain ? 'processing' : 'failed',
            'agent_commission_provider_request_id' => $requestId,
            'agent_commission_error' => substr((string) ($result['message'] ?? 'Agent commission payout failed.'), 0, 1000),
            'agent_commission_processed_at' => $uncertain ? null : now(),
        ]);

        Log::warning('Agent commission payout did not complete.', [
            'payment_id' => $payment->id,
            'provider' => $provider,
            'source' => $source,
            'uncertain' => $uncertain,
        ]);

        return $result;
    }

    private function reference(Payment $payment): string
    {
        return 'ALX-COM-'.$payment->id.'-'.substr(sha1(($payment->payment_reference ?? '').'|'.$payment->id), 0, 10);
    }
}
