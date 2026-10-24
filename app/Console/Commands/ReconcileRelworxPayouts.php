<?php

namespace App\Console\Commands;

use App\Models\PayoutRequest;
use App\Services\Payments\PayoutExecutionService;
use App\Services\Payments\RelworxGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileRelworxPayouts extends Command
{
    protected $signature = 'payouts:reconcile-relworx {--hours=48 : Reconcile processing payouts created within this many hours}';

    protected $description = 'Reconcile processing Relworx wallet payouts with the provider';

    public function handle(RelworxGateway $gateway, PayoutExecutionService $payouts): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $requests = PayoutRequest::query()
            ->where('provider', 'relworx')
            ->where('status', 'processing')
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('id')
            ->limit(250)
            ->get();

        foreach ($requests as $payout) {
            $result = $gateway->queryPayout($payout);
            $status = (string) ($result['status'] ?? 'unknown');
            if (! in_array($status, ['success', 'failed'], true)
                || ($result['reference_matches'] ?? true) !== true) {
                if ($payout->created_at && $payout->created_at->lte(now()->subMinutes(5))) {
                    Log::warning('Relworx payout remains processing after five minutes.', [
                        'payout_request_id' => $payout->id,
                        'reference' => $payout->reference,
                        'provider_status' => $status,
                    ]);
                }

                continue;
            }

            if (! $this->matchesPayout($payout, $result)) {
                continue;
            }

            $payouts->settle($payout, $status, $result);
            $this->info(ucfirst($status)." Relworx payout {$payout->id}.");
        }

        return self::SUCCESS;
    }

    private function matchesPayout(PayoutRequest $payout, array $result): bool
    {
        $currency = strtoupper((string) ($result['currency'] ?? ''));
        $amount = $result['amount'] ?? null;
        $matches = ($currency === '' || $currency === strtoupper((string) $payout->currency))
            && ($amount === null || abs((float) $amount - (float) $payout->amount) < 0.001);

        if (! $matches) {
            Log::warning('Relworx reconciliation returned mismatched payout details.', [
                'payout_request_id' => $payout->id,
                'expected_amount' => $payout->amount,
                'received_amount' => $amount,
                'expected_currency' => $payout->currency,
                'received_currency' => $currency,
            ]);
        }

        return $matches;
    }
}
