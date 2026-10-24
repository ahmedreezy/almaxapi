<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentPricingService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PaymentPricingTest extends TestCase
{
    public function test_relworx_quote_grosses_up_a_thousand_ugx_to_1031(): void
    {
        Config::set('services.payment_gateway.currency', 'UGX');
        Config::set('services.relworx.collection_fee_rate', 0.03);

        $quote = app(PaymentPricingService::class)->quote(1000, 'relworx');

        $this->assertSame(1000.0, $quote['baseAmount']);
        $this->assertSame(31.0, $quote['providerFee']);
        $this->assertSame(1031.0, $quote['totalCharge']);
        $this->assertSame('UGX', $quote['currency']);
        $this->assertSame('relworx', $quote['provider']);
    }

    public function test_jpesa_quote_remains_unchanged(): void
    {
        Config::set('services.payment_gateway.currency', 'UGX');

        $quote = app(PaymentPricingService::class)->quote(1000, 'jpesa');

        $this->assertSame(0.0, $quote['providerFee']);
        $this->assertSame(1000.0, $quote['totalCharge']);
    }
}
