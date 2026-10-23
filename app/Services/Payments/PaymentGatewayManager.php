<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use InvalidArgumentException;

class PaymentGatewayManager
{
    public const PROVIDERS = ['jpesa', 'relworx'];

    public function activeName(): string
    {
        $provider = strtolower((string) PaymentGatewaySetting::current()->active_provider);

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'jpesa';
    }

    public function active(): PaymentGateway
    {
        return $this->gateway($this->activeName());
    }

    public function forPayment(Payment $payment): PaymentGateway
    {
        return $this->gateway($payment->provider ?: 'jpesa');
    }

    public function gateway(string $provider): PaymentGateway
    {
        return match (strtolower($provider)) {
            'jpesa' => app(JpesaGateway::class),
            'relworx' => app(RelworxGateway::class),
            default => throw new InvalidArgumentException("Unsupported payment gateway: {$provider}"),
        };
    }
}
