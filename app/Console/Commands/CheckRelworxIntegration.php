<?php

namespace App\Console\Commands;

use App\Services\Payments\RelworxGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckRelworxIntegration extends Command
{
    protected $signature = 'payments:doctor-relworx {--live-webhook : Send a signed, non-financial callback probe}';

    protected $description = 'Verify Relworx API access and the signed callback path without moving money';

    public function handle(RelworxGateway $gateway): int
    {
        $configured = $gateway->readiness(false);
        if (! ($configured['configured'] ?? false)) {
            $this->error('Relworx configuration is incomplete: '.implode(', ', $configured['missing'] ?? []));

            return self::FAILURE;
        }

        $live = $gateway->readiness(true);
        if (! ($live['ready'] ?? false)) {
            $this->error('Relworx API connectivity failed: '.($live['message'] ?? 'Unknown error.'));

            return self::FAILURE;
        }
        $this->info('Relworx API connectivity: OK');

        if (! $this->option('live-webhook')) {
            return self::SUCCESS;
        }

        $url = trim((string) config('services.relworx.webhook_url', ''));
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            $this->error('Relworx webhook URL must be a public HTTPS URL.');

            return self::FAILURE;
        }

        $reference = 'ALX-DIAG-'.now()->format('YmdHis');
        $payload = [
            'status' => 'pending',
            'customer_reference' => $reference,
            'internal_reference' => $reference,
        ];
        $timestamp = time();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->timeout(20)
                ->withHeaders([
                    'Relworx-Signature' => 't='.$timestamp.',v='.$gateway->webhookSignature($payload, $timestamp),
                ])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            $this->error('Signed webhook connectivity failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->status() !== 200) {
            $this->error('Signed webhook connectivity returned HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        $this->info('Relworx signed webhook connectivity: HTTP 200');

        return self::SUCCESS;
    }
}
