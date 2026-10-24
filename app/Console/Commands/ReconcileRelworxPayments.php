<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileRelworxPayments extends Command
{
    protected $signature = 'payments:reconcile-relworx {--hours=48 : Reconcile pending requests created within this many hours}';

    protected $description = 'Reconcile recent pending Relworx collections with the provider';

    public function handle(PaymentGatewayManager $gateways, PaymentSettlementService $settlements): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $payments = Payment::with(['subscription.group'])
            ->where('provider', 'relworx')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('id')
            ->limit(250)
            ->get();

        foreach ($payments as $payment) {
            if (! $payment->subscription) {
                continue;
            }

            $result = $gateways->forPayment($payment)->queryCollection($payment, $payment->payment_reference);
            $status = $result['status'] ?? 'unknown';
            if ($status === 'success' && ($result['success'] ?? false) && $this->matchesPayment($payment, $result)) {
                $settlements->apply(
                    $payment->subscription,
                    true,
                    $result['transaction_id'] ?? null,
                    'relworx-scheduler',
                    $this->providerData($result)
                );
                $this->info("Confirmed Relworx payment {$payment->id}.");
            } elseif ($status === 'failed' && ($result['reference_matches'] ?? true)) {
                $settlements->apply(
                    $payment->subscription,
                    false,
                    $result['transaction_id'] ?? null,
                    'relworx-scheduler',
                    $this->providerData($result),
                    'provider',
                    (string) ($result['message'] ?? 'Relworx reported a failed transaction.')
                );
            } elseif ($payment->created_at && $payment->created_at->lte(now()->subMinutes(5))) {
                Log::warning('Relworx payment remains pending after five minutes.', [
                    'payment_id' => $payment->id,
                    'reference' => $payment->payment_reference,
                    'provider_status' => $status,
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function matchesPayment(Payment $payment, array $result): bool
    {
        if (($result['reference_matches'] ?? true) !== true) {
            return false;
        }

        $currency = strtoupper((string) ($result['currency'] ?? ''));
        $expectedCurrency = strtoupper((string) config('services.payment_gateway.currency', 'UGX'));
        $amount = $result['amount'] ?? null;
        $expectedAmount = (float) ($payment->customer_charge_amount ?: $payment->amount);
        $matches = ($currency === '' || $currency === $expectedCurrency)
            && ($amount === null || abs((float) $amount - $expectedAmount) < 0.001);

        if (! $matches) {
            Log::warning('Relworx reconciliation returned mismatched payment details.', [
                'payment_id' => $payment->id,
                'expected_amount' => $expectedAmount,
                'received_amount' => $amount,
                'expected_currency' => $expectedCurrency,
                'received_currency' => $currency,
            ]);
        }

        return $matches;
    }

    private function providerData(array $result): array
    {
        $amount = isset($result['amount']) ? (float) $result['amount'] : null;
        $fee = isset($result['provider_fee_actual']) ? (float) $result['provider_fee_actual'] : null;

        return [
            'provider_request_id' => $result['provider_request_id'] ?? null,
            'provider_fee_actual' => $fee,
            'provider_net_amount' => $amount !== null && $fee !== null ? max(0, $amount - $fee) : null,
            'completed_at' => $result['completed_at'] ?? null,
        ];
    }
}
