<?php

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportMessage;

class SupportFastReplyService
{
    public function __construct(private readonly SupportToolExecutor $tools) {}

    /**
     * Resolve safe, common requests without paying for one or more model round trips.
     *
     * @return array<string, mixed>|null
     */
    public function respond(SupportConversation $conversation, SupportMessage $incoming): ?array
    {
        $message = mb_strtolower(trim($incoming->body));
        if (! $this->isPackageDiscoveryRequest($message)) {
            return null;
        }

        $result = $this->tools->execute($conversation, $incoming, 'get_available_packages', []);
        if (! ($result['ok'] ?? false)) {
            return null;
        }

        $packages = collect($result['packages'] ?? []);
        if ($packages->isEmpty()) {
            $reply = 'There are no packages currently open for purchase. Please check again later, and do not send money for a package that is not shown as available.';
            $summary = 'Customer asked about purchasing a package; none are currently available.';
        } else {
            $lines = $packages->map(function ($package): string {
                $name = trim((string) data_get($package, 'name', 'Package'));
                $price = number_format((float) data_get($package, 'price', 0), 0);
                $plan = ucfirst((string) data_get($package, 'plan_type', ''));
                $details = $plan !== '' ? " ({$plan})" : '';

                return "• {$name}{$details} — UGX {$price}";
            })->implode("\n");

            $reply = "These packages are currently available:\n{$lines}\n\nChoose the package on the Almax home page to continue with MTN Mobile Money or Airtel Money.";
            $summary = 'Customer asked what packages are currently available to purchase.';
        }

        return [
            'reply' => $reply,
            'language' => 'en',
            'category' => 'subscription',
            'sentiment' => 'neutral',
            'priority' => 'normal',
            'action' => 'answer',
            'summary' => $summary,
            'resolved' => false,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'model' => 'deterministic-fast-path',
            'performance' => [
                'source' => 'package_fast_path',
                'openai_rounds' => 0,
                'openai_ms' => 0,
            ],
        ];
    }

    private function isPackageDiscoveryRequest(string $message): bool
    {
        if ($message === '' || preg_match(
            '/\b(my|active|current)\s+(package|plan|subscription)\b|\b(payment|paid|transaction|failed|charged|deducted|pending|receipt)\b/u',
            $message
        )) {
            return false;
        }

        $purchaseIntent = preg_match(
            '/\b(purchase|purchasing|buy|buying|subscribe|available|availability|price|prices|cost)\b/u',
            $message
        );
        $packageQuestion = preg_match(
            '/\b(which|what|show|list|see|find|have|offer)\b.{0,40}\b(packages?|plans?)\b|\b(packages?|plans?)\b.{0,40}\b(available|offered|prices?|cost)\b/u',
            $message
        );

        return (bool) ($purchaseIntent || $packageQuestion);
    }
}
