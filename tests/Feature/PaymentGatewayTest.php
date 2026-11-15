<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Models\PaymentGatewaySwitch;
use App\Models\Subscription;
use App\Models\User;
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

    public function test_relworx_status_falls_back_to_internal_reference_after_unknown_customer_lookup(): void
    {
        $this->configureRelworx();
        $payment = new Payment([
            'payment_reference' => 'ALX-customer-reference',
            'provider_request_id' => 'RWX-INTERNAL-123',
        ]);
        $payment->id = 100;

        Http::fake(function ($request) {
            if ($request->data()['internal_reference'] === 'ALX-customer-reference') {
                return Http::response(['success' => false, 'message' => 'Request not found.'], 404);
            }

            return Http::response([
                'success' => true,
                'request_status' => 'success',
                'customer_reference' => 'ALX-customer-reference',
                'internal_reference' => 'RWX-INTERNAL-123',
                'provider_transaction_id' => 'RWX-TXN-123',
                'amount' => 1000,
                'currency' => 'UGX',
            ]);
        });

        $result = app(RelworxGateway::class)->queryCollection($payment);

        $this->assertTrue($result['success']);
        $this->assertSame('RWX-TXN-123', $result['transaction_id']);
        Http::assertSentCount(2);
    }

    public function test_relworx_doctor_checks_api_and_signed_webhook_without_moving_money(): void
    {
        $this->configureRelworx();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/payment-requests/transactions')) {
                return Http::response(['success' => true, 'transactions' => []]);
            }

            if ($request->url() === 'https://almaxpredictions.com/api/payments/webhooks/relworx') {
                return Http::response(['message' => 'No matching payment'], 200);
            }

            return Http::response([], 500);
        });

        $this->artisan('payments:doctor-relworx --live-webhook')
            ->expectsOutput('Relworx API connectivity: OK')
            ->expectsOutput('Relworx signed webhook connectivity: HTTP 200')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://almaxpredictions.com/api/payments/webhooks/relworx'
            && str_starts_with((string) $request->header('Relworx-Signature')[0], 't='));
    }

    public function test_relworx_reconciliation_recovers_a_recent_failed_payment(): void
    {
        $this->configureRelworx();
        $user = User::create([
            'username' => 'Relworx Recovery User',
            'phone' => '0712000100',
            'password_hash' => Hash::make('pass'),
        ]);
        $reference = 'ALX-relworx-scheduled-recovery';
        $sub = Subscription::create([
            'user_id' => $user->id,
            'odds_type' => '2',
            'plan_type' => 'daily',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'amount' => 1000,
            'status' => 'failed',
            'payment_reference' => $reference,
        ]);
        $payment = Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 1000,
            'customer_charge_amount' => 1000,
            'plan_type' => 'daily',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'status' => 'failed',
            'payment_reference' => $reference,
            'provider_request_id' => 'RWX-SCHEDULED-123',
            'provider' => 'relworx',
        ]);

        Http::fake(fn () => Http::response([
            'success' => true,
            'request_status' => 'success',
            'customer_reference' => $reference,
            'internal_reference' => 'RWX-SCHEDULED-123',
            'provider_transaction_id' => 'RWX-TXN-SCHEDULED',
            'amount' => 1000,
            'currency' => 'UGX',
            'charge' => 30,
            'completed_at' => now()->toIso8601String(),
        ]));

        $this->artisan('payments:reconcile-relworx')->assertSuccessful();

        $this->assertDatabaseHas('subscriptions', ['id' => $sub->id, 'status' => 'active']);
        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame('RWX-TXN-SCHEDULED', $payment->transaction_id);
        $this->assertNotEmpty($payment->receipt_number);
    }

    public function test_relworx_collection_webhook_confirms_payment_receipt_and_package(): void
    {
        $this->configureRelworx();
        $user = User::create([
            'username' => 'Relworx Webhook User',
            'phone' => '0712000101',
            'password_hash' => Hash::make('pass'),
        ]);
        $group = Group::create([
            'name' => 'Relworx Webhook Package',
            'odds_type' => '2',
            'plan_type' => 'daily',
            'price' => 1000,
            'betslip_link' => 'https://example.test/relworx-webhook-ticket',
            'betslip_code' => 'RWX-WEBHOOK',
            'is_special' => false,
            'is_active' => true,
        ]);
        $reference = 'ALX-relworx-webhook-success';
        $sub = Subscription::create([
            'user_id' => $user->id,
            'group_id' => $group->id,
            'odds_type' => '2',
            'plan_type' => 'daily',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'amount' => 1000,
            'status' => 'pending',
            'payment_reference' => $reference,
        ]);
        $payment = Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 1000,
            'customer_charge_amount' => 1000,
            'plan_type' => 'daily',
            'payment_method' => 'mtn',
            'phone' => $user->phone,
            'status' => 'pending',
            'payment_reference' => $reference,
            'provider_request_id' => 'RWX-WEBHOOK-123',
            'provider' => 'relworx',
        ]);
        $payload = [
            'status' => 'success',
            'message' => 'Request payment completed successfully.',
            'customer_reference' => $reference,
            'internal_reference' => 'RWX-WEBHOOK-123',
            'amount' => 1000,
            'currency' => 'UGX',
            'charge' => 30,
            'completed_at' => now()->toIso8601String(),
        ];
        $timestamp = time();

        $this->withHeader('Relworx-Signature', "t={$timestamp},v=".$this->relworxSignature($payload, $timestamp))
            ->postJson('/api/payments/webhooks/relworx', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Processed');

        $sub->refresh();
        $payment->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame('https://example.test/relworx-webhook-ticket', $sub->betslip_link);
        $this->assertSame('RWX-WEBHOOK', $sub->betslip_code);
        $this->assertSame('confirmed', $payment->status);
        $this->assertNotEmpty($payment->receipt_number);
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
