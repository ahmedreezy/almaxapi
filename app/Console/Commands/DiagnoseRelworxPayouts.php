<?php

namespace App\Console\Commands;

use App\Models\PayoutRequest;
use App\Services\Payments\RelworxGateway;
use Illuminate\Console\Command;

class DiagnoseRelworxPayouts extends Command
{
    protected $signature = 'payouts:diagnose-relworx {--limit=25 : Maximum recent processing payouts to inspect}';

    protected $description = 'Read Relworx status for non-terminal payouts without changing local records';

    public function handle(RelworxGateway $gateway): int
    {
        $limit = min(100, max(1, (int) $this->option('limit')));
        $summary = [
            'local_pending' => PayoutRequest::query()->where('provider', 'relworx')->where('status', 'pending')->count(),
            'local_processing' => PayoutRequest::query()->where('provider', 'relworx')->where('status', 'processing')->count(),
            'older_than_48_hours' => PayoutRequest::query()
                ->where('provider', 'relworx')
                ->where('status', 'processing')
                ->where('created_at', '<', now()->subHours(48))
                ->count(),
            'scanned' => 0,
            'provider_success' => 0,
            'provider_failed' => 0,
            'provider_pending' => 0,
            'provider_unknown' => 0,
            'reference_mismatch' => 0,
            'missing_provider_reference' => 0,
            'within_48h_success' => 0,
            'within_48h_failed' => 0,
            'within_48h_pending' => 0,
            'within_48h_unknown' => 0,
            'older_success' => 0,
            'older_failed' => 0,
            'older_pending' => 0,
            'older_unknown' => 0,
        ];

        $payouts = PayoutRequest::query()
            ->where('provider', 'relworx')
            ->where('status', 'processing')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($payouts as $payout) {
            $summary['scanned']++;
            if (trim((string) $payout->provider_request_id) === '') {
                $summary['missing_provider_reference']++;
            }

            $result = $gateway->queryPayout($payout);
            if (($result['reference_matches'] ?? true) !== true) {
                $summary['reference_mismatch']++;
            }

            $status = (string) ($result['status'] ?? 'unknown');
            $key = match ($status) {
                'success' => 'provider_success',
                'failed' => 'provider_failed',
                'pending' => 'provider_pending',
                default => 'provider_unknown',
            };
            $summary[$key]++;

            $ageBucket = $payout->created_at && $payout->created_at->lt(now()->subHours(48))
                ? 'older'
                : 'within_48h';
            $summary[$ageBucket.'_'.($status === 'success' || $status === 'failed' || $status === 'pending' ? $status : 'unknown')]++;
        }

        $json = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->line('Relworx payout diagnosis: '.$json);
        $this->line('::notice title=Relworx payout diagnosis::'.$json);

        return self::SUCCESS;
    }
}
