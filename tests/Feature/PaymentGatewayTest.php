<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Models\PaymentGatewaySwitch;
use App\Services\Payments\RelworxGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    public function test_developer_can_view_gateway_state_but_owner_cannot(): void
    {
        $this->withHeaders($this->developerHeaders())
            ->getJson('/api/developer/payment-gateway')
            ->assertOk()
            ->assertJsonPath('activeProvider', 'jpesa')
            ->assertJsonStructure(['providers' => ['jpesa', 'relworx']]);

        $this->withHeaders($this->createAdmin('gateway-owner')['headers'])
            ->getJson('/api/developer/payment-gateway')
            ->assertForbidden();
    }

    public function test_relworx_switch_is_blocked_when_configuration_is_incomplete(): void
    {
        Config::set('services.relworx.api_key', '');

        $this->withHeaders($this->developerHeaders())
            ->patchJson('/api/developer/payment-gateway', ['provider' => 'relworx'])
            ->assertStatus(422)
            ->assertJsonPath('readiness.ready', false);

        $this->assertSame('jpesa', PaymentGatewaySetting::current()->active_provider);
    }

    public function test_relworx_switch_is_blocked_when_live_account_validation_fails(): void
    {
        $this->configureRelworx();
        Http::fake(['*/payment-requests/transactions*' => Http::response([
            'success' => false,
            'message' => 'API key is not authorized for this account.',
        ], 403)]);

        $this->withHeaders($this->developerHeaders())
            ->patchJson('/api/developer/payment-gateway', ['provider' => 'relworx'])
            ->assertStatus(422)
            ->assertJsonPath('readiness.ready', false);

        $this->assertSame('jpesa', PaymentGatewaySetting::current()->active_provider);
        $this->assertDatabaseCount('payment_gateway_switches', 0);
    }

    public function test_ready_relworx_account_can_be_activated_and_is_audited(): void
    {
        $this->configureRelworx();
        Http::fake(['*/payment-requests/transactions*' => Http::response(['success' => true, 'transactions' => []])]);

        $this->withHeaders($this->developerHeaders())
            ->patchJson('/api/developer/payment-gateway', ['provider' => 'relworx'])
            ->assertOk()
            ->assertJsonPath('activeProvider', 'relworx');

        $this->assertDatabaseHas('payment_gateway_settings', ['id' => 1, 'active_provider' => 'relworx']);
        $this->assertDatabaseHas('payment_gateway_switches', ['from_provider' => 'jpesa', 'to_provider' => 'relworx']);
        $this->assertSame(1, PaymentGatewaySwitch::count());
    }

    public function test_switching_provider_keeps_exactly_one_gateway_active(): void
    {
        $this->configureRelworx();
        Http::fake(['*/payment-requests/transactions*' => Http::response(['success' => true, 'transactions' => []])]);

        $this->withHeaders($this->developerHeaders())
            ->patchJson('/api/developer/payment-gateway', ['provider' => 'relworx'])
            ->assertOk()
            ->assertJsonPath('activeProvider', 'relworx');

        $this->assertDatabaseCount('payment_gateway_settings', 1);
        $this->assertSame('relworx', PaymentGatewaySetting::current()->active_provider);
        $this->assertSame('relworx', app(\App\Services\Payments\PaymentGatewayManager::class)->activeName());
    }

    public function test_relworx_signature_accepts_current_hex_signature_and_rejects_stale_signature(): void
    {
        $this->configureRelworx();
        $payload = [
            'status' => 'success',
            'customer_reference' => 'ALX-22-10-123456',
            'internal_reference' => 'relworx-internal-reference',
            'amount' => 500,
            'currency' => 'UGX',
        ];
        $timestamp = time();
        $signature = $this->relworxSignature($payload, $timestamp);
        $request = Request::create(
            '/api/payments/webhooks/relworx',
            'POST',
            $payload,
            [],
            [],
            ['HTTP_RELWORX_SIGNATURE' => "t={$timestamp},v={$signature}"]
        );

        $gateway = app(RelworxGateway::class);
        $this->assertTrue($gateway->verifyWebhook($request));
        $this->assertSame('valid', $gateway->webhookVerification($request)['reason']);

        $stale = $timestamp - 1000;
        $staleRequest = Request::create(
            '/api/payments/webhooks/relworx',
            'POST',
            $payload,
            [],
            [],
            ['HTTP_RELWORX_SIGNATURE' => 't='.$stale.',v='.$this->relworxSignature($payload, $stale)]
        );
        $this->assertFalse($gateway->verifyWebhook($staleRequest));
        $this->assertSame('stale_timestamp', $gateway->webhookVerification($staleRequest)['reason']);

        Config::set('services.relworx.webhook_url', 'https://www.almaxpredictions.com/api/payments/webhooks/relworx');
        $this->assertSame('signature_mismatch', $gateway->webhookVerification($request)['reason']);
    }

    public function test_relworx_status_uses_customer_reference_before_stale_internal_reference(): void
    {
        $this->configureRelworx();
        $payment = new Payment([
            'payment_reference' => 'ALX-current-customer-reference',
            'provider_request_id' => 'stale-failed-internal-reference',
        ]);
        $payment->id = 99;

        Http::fake(fn () => Http::response([
            'request_status' => 'success',
            'customer_reference' => 'ALX-current-customer-reference',
            'internal_reference' => 'current-success-internal-reference',
            'provider_transaction_id' => '44483745949',
            'amount' => 1000,
            'charge' => 30,
            'currency' => 'UGX',
            'completed_at' => '2026-10-24T07:22:03.009+03:00',
        ]));

        $result = app(RelworxGateway::class)->queryCollection($payment);

        $this->assertTrue($result['success']);
        $this->assertSame('current-success-internal-reference', $result['provider_request_id']);
        $this->assertSame(30.0, $result['provider_fee_actual']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->data()['internal_reference'] === 'ALX-current-customer-reference');
    }

    private function configureRelworx(): void
    {
        Config::set('services.relworx', [
            'api_url' => 'https://payments.relworx.com/api',
            'api_key' => 'relworx-test-key',
            'account_no' => 'RELTEST123',
            'webhook_key' => 'webhook-test-key',
            'webhook_url' => 'https://almaxpredictions.com/api/payments/webhooks/relworx',
            'webhook_tolerance' => 300,
        ]);
        Config::set('services.mobile_money.agent_commission.mobile', '+256700000001');
    }

    private function relworxSignature(array $payload, int $timestamp): string
    {
        $params = [
            'customer_reference' => $payload['customer_reference'],
            'internal_reference' => $payload['internal_reference'],
            'status' => $payload['status'],
        ];
        ksort($params);
        $signed = config('services.relworx.webhook_url').$timestamp;
        foreach ($params as $key => $value) {
            $signed .= $key.$value;
        }

        return hash_hmac('sha256', $signed, (string) config('services.relworx.webhook_key'));
    }

    private function developerHeaders(): array
    {
        $dev = AdminUser::create([
            'username' => 'gateway-developer-'.uniqid(),
            'role' => 'developer',
            'password_hash' => Hash::make('DevPassword@2026'),
        ]);
        $token = $dev->createToken('gateway-dev-token', ['role:developer'])->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }
}
