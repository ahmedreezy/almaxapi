<?php

namespace App\Services\Payments;

class PaymentPricingService
{
    public function quote(float $baseAmount, string $provider): array
    {
        $baseAmount = round(max(0, $baseAmount), 2);
        $provider = strtolower($provider);
        $rate = $provider === 'relworx'
            ? min(0.99, max(0, (float) config('services.relworx.collection_fee_rate', 0.03)))
            : 0.0;

        // The Relworx collection fee is deducted from the requested amount.
        // Gross up and round upward so the provider net is never below the
        // package price when the configured rate is accurate.
        $currency = strtoupper((string) config('services.payment_gateway.currency', 'UGX'));
        $total = $rate > 0
            ? ($currency === 'UGX'
                ? ceil($baseAmount / (1 - $rate))
                : ceil(($baseAmount / (1 - $rate)) * 100) / 100)
            : $baseAmount;
        $fee = round($total - $baseAmount, 2);

        return [
            'baseAmount' => $baseAmount,
            'providerFee' => $fee,
            'totalCharge' => $total,
            'currency' => $currency,
            'provider' => $provider,
            'feeRate' => $rate,
        ];
    }
}
