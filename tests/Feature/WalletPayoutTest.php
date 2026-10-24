<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\AgentCommissionService;
use App\Services\Payments\RelworxGateway;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalletPayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.payment_gateway.currency', 'UGX');
        Config::set('services.payouts', [
            'execution_mode' => 'request_only',
            'owner_msisdn' => '+256700000111',
            'developer_msisdn' => '+256700000222',
        ]);
        Config::set('services.mobile_money.agent_commission', [
            'enabled' => true,
            'ratio' => 0.2,
            'mobile' => '+256700000222',
            'recipient_type' => 'mobile',
            'recipient_mobile' => '+256700000222',
        ]);
    }

    public function test_relworx_confirmation_accrues_separate_balances_without_sending_payment(): void
    {
        Http::fake();
        $payment = $this->confirmedPayment('relworx', 5000);

        $result = app(AgentCommissionService::class)->process($payment, 'test');

        $this->assertTrue($result['accrued']);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'agent_commission_amount' => 1000,
            'agent_commission_status' => 'accrued',
        ]);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'payment_id' => $payment->id,
            'entry_type' => 'payment_credit',
            'amount' => 4000,
        ]);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'payment_id' => $payment->id,
            'entry_type' => 'commission_credit',
            'amount' => 1000,
        ]);
        Http::assertNothingSent();
    }

    public function test_accrual_is_idempotent(): void
    {
        Http::fake();
        $payment = $this->confirmedPayment('relworx', 5000);
        $service = app(AgentCommissionService::class);

        $service->process($payment, 'first');
        $service->process($payment->fresh(), 'duplicate');

        $this->assertDatabaseCount('wallet_ledger_entries', 2);
    }

    public function test_legacy_unsurcharged_relworx_payment_allocates_only_provider_net(): void
    {
        Http::fake();
        $payment = $this->confirmedPayment('relworx', 1000, 970);

        app(AgentCommissionService::class)->process($payment, 'legacy-reconciliation');

        $this->assertDatabaseHas('wallet_ledger_entries', [
            'payment_id' => $payment->id,
            'entry_type' => 'payment_credit',
            'amount' => 770,
        ]);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'payment_id' => $payment->id,
            'entry_type' => 'commission_credit',
            'amount' => 200,
        ]);
    }

    public function test_surcharged_relworx_payment_preserves_full_base_allocation(): void
    {
        Http::fake();
        $payment = $this->confirmedPayment('relworx', 1000, 1000);
        $payment->update([
            'customer_charge_amount' => 1031,
            'provider_fee_estimate' => 31,
            'provider_fee_actual' => 31,
        ]);

        app(AgentCommissionService::class)->process($payment->fresh(), 'surcharged-payment');

        $this->assertDatabaseHas('wallet_ledger_entries', [
            'payment_id' => $payment->id,
            'entry_type' => 'payment_credit',
            'amount' => 800,
        ]);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'payment_id' => $payment->id,
            'entry_type' => 'commission_credit',
            'amount' => 200,
        ]);
    }

    public function test_developer_can_reserve_and_cancel_own_relworx_payout(): void
    {
        Http::fake();
        app(AgentCommissionService::class)->process($this->confirmedPayment('relworx', 5000), 'test');
        $headers = $this->developerHeaders('wallet-developer');

        $response = $this->withHeaders($headers)->postJson('/api/developer/payout-requests', [
            'amount' => 600,
            'request_token' => 'developer-reserve-600',
        ]);
        $response->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('wallet.provider', 'relworx')
            ->assertJsonPath('wallet.available', 400)
            ->assertJsonPath('wallet.reserved', 600);

        $id = $response->json('request.id');
        $this->withHeaders($headers)
            ->postJson("/api/developer/payout-requests/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('request.status', 'cancelled')
            ->assertJsonPath('wallet.available', 1000);
    }

    public function test_payout_uses_fixed_destination_and_rejects_overdraw(): void
    {
        Http::fake();
        app(AgentCommissionService::class)->process($this->confirmedPayment('relworx', 5000), 'test');
        $headers = $this->developerHeaders('fixed-destination-developer');

        $this->withHeaders($headers)
            ->postJson('/api/developer/payout-requests', [
                'amount' => 1200,
                'request_token' => 'developer-overdraw-1200',
                'destination_msisdn' => '+256799999999',
            ])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->postJson('/api/developer/payout-requests', [
                'amount' => 500,
                'request_token' => 'developer-fixed-500',
                'destination_msisdn' => '+256799999999',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('payout_requests', [
            'beneficiary' => 'developer',
            'amount' => 500,
            'destination_msisdn' => '+256700000222',
            'status' => 'pending',
        ]);
    }

    public function test_admin_wallet_exposes_only_owner_balance_and_payout_data(): void
    {
        Http::fake();
        app(AgentCommissionService::class)->process($this->confirmedPayment('relworx', 5000), 'test');

        $this->withHeaders($this->createAdmin('wallet-owner')['headers'])
            ->getJson('/api/admin/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.provider', 'relworx')
            ->assertJsonPath('wallet.available', 4000)
            ->assertJsonMissingPath('today_revenue')
            ->assertJsonMissingPath('relworx_wallet')
            ->assertJsonMissingPath('developer_liability')
            ->assertJsonStructure([
                'wallet',
                'destination_configured',
                'execution_mode',
                'requests',
            ]);
        Http::assertNothingSent();
    }

    public function test_relworx_payout_probe_reports_disabled_without_sending_money(): void
    {
        Http::fake();
        Config::set('services.relworx.payouts_enabled', false);

        $gateway = app(RelworxGateway::class);
        $result = $gateway->initiatePayout(
            1,
            'ALX-PAYOUT-PROBE',
            'Technical payout capability probe',
            '+256700000111',
        );

        $this->assertFalse($gateway->capabilities()['payouts']);
        $this->assertFalse($result['success']);
        $this->assertFalse($result['pending']);
        $this->assertFalse($result['final']);
        $this->assertSame('Relworx API payouts are not enabled for this account.', $result['message']);
        Http::assertNothingSent();
    }

    public function test_admin_button_submits_owner_payout_to_relworx_once(): void
    {
        $this->enableImmediateRelworxPayouts();
        Http::fake(['*/mobile-money/send-payment' => Http::response([
            'success' => true,
            'message' => 'Send payment in progress.',
            'internal_reference' => 'RWX-OWNER-500',
        ])]);
        app(AgentCommissionService::class)->process($this->confirmedPayment('relworx', 5000), 'test');
        $headers = $this->createAdmin('live-wallet-owner')['headers'];
        $payload = [
            'amount' => 500,
            'request_token' => 'owner-live-submit-500',
        ];

        $response = $this->withHeaders($headers)->postJson('/api/admin/payout-requests', $payload);

        $response->assertCreated()
            ->assertJsonPath('request.status', 'processing')
            ->assertJsonPath('request.beneficiary', 'owner')
            ->assertJsonPath('wallet.available', 3500)
            ->assertJsonPath('wallet.reserved', 500);
        $this->assertDatabaseHas('payout_requests', [
            'beneficiary' => 'owner',
            'status' => 'processing',
            'destination_msisdn' => '+256700000111',
            'provider_request_id' => 'RWX-OWNER-500',
        ]);
        $this->withHeaders($headers)
            ->postJson('/api/admin/payout-requests', $payload)
            ->assertCreated()
            ->assertJsonPath('request.id', $response->json('request.id'));
        $this->assertDatabaseCount('payout_requests', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://payments.relworx.com/api/mobile-money/send-payment'
            && (float) $request['amount'] === 500.0
            && $request['msisdn'] === '+256700000111');
    }

    public function test_developer_button_submits_commission_payout_to_relworx(): void
    {
        $this->enableImmediateRelworxPayouts();
        Http::fake(['*/mobile-money/send-payment' => Http::response([
            'success' => true,
            'message' => 'Send payment in progress.',
            'internal_reference' => 'RWX-DEVELOPER-500',
        ])]);
        app(AgentCommissionService::class)->process($this->confirmedPayment('relworx', 5000), 'test');

        $this->withHeaders($this->developerHeaders('live-wallet-developer'))
            ->postJson('/api/developer/payout-requests', [
                'amount' => 500,
                'request_token' => 'developer-live-submit-500',
            ])
            ->assertCreated()
            ->assertJsonPath('request.status', 'processing')
            ->assertJsonPath('request.beneficiary', 'developer')
            ->assertJsonPath('wallet.available', 500);

        Http::assertSent(fn ($request) => $request['msisdn'] === '+256700000222');
    }

    public function test_definitively_rejected_payout_releases_reserved_funds(): void
    {
        $this->enableImmediateRelworxPayouts();
        Http::fake(['*/mobile-money/send-payment' => Http::response([
            'success' => false,
            'message' => 'Payout rejected.',
        ], 422)]);
        app(AgentCommissionService::class)->process($this->confirmedPayment('relworx', 5000), 'test');

        $this->withHeaders($this->createAdmin('rejected-wallet-owner')['headers'])
            ->postJson('/api/admin/payout-requests', [
                'amount' => 500,
                'request_token' => 'owner-rejected-submit-500',
            ])
            ->assertCreated()
            ->assertJsonPath('request.status', 'failed')
            ->assertJsonPath('wallet.available', 4000)
            ->assertJsonPath('wallet.reserved', 0);

        $this->assertDatabaseHas('wallet_ledger_entries', [
            'entry_type' => 'payout_release',
            'amount' => 500,
        ]);
    }

    public function test_relworx_payout_webhook_completes_once_and_records_fee(): void
    {
        $this->enableImmediateRelworxPayouts();
        Http::fake(['*/mobile-money/send-payment' => Http::response([
            'success' => true,
            'message' => 'Send payment in progress.',
            'internal_reference' => 'RWX-COMPLETE-500',
        ])]);
        app(AgentCommissionService::class)->process($this->confirmedPayment('relworx', 5000), 'test');
        $created = $this->withHeaders($this->createAdmin('completed-wallet-owner')['headers'])
            ->postJson('/api/admin/payout-requests', [
                'amount' => 500,
                'request_token' => 'owner-completed-submit-500',
            ]);

        $payload = [
            'status' => 'success',
            'customer_reference' => $created->json('request.reference'),
            'internal_reference' => 'RWX-COMPLETE-500',
            'provider_transaction_id' => 'TXN-500',
            'amount' => 500,
            'currency' => 'UGX',
            'charge' => 15,
            'completed_at' => '2026-10-24T12:00:00+03:00',
        ];
        $timestamp = time();
        $signature = $this->relworxSignature($payload, $timestamp);

        foreach ([1, 2] as $attempt) {
            $this->withHeader('Relworx-Signature', "t={$timestamp},v={$signature}")
                ->postJson('/api/payments/webhooks/relworx', $payload)
                ->assertOk();
        }

        $this->assertDatabaseHas('payout_requests', [
            'reference' => $payload['customer_reference'],
            'status' => 'completed',
            'transaction_id' => 'TXN-500',
            'provider_fee_actual' => 15,
        ]);
        $this->assertDatabaseCount('payout_requests', 1);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'entry_type' => 'payout_fee',
            'amount' => -15,
        ]);
        $this->assertSame(3485.0, (float) $this->withHeaders($this->createAdmin('completed-wallet-reader')['headers'])
            ->getJson('/api/admin/wallet')->json('wallet.available'));
    }

    public function test_relworx_minimum_payout_is_enforced(): void
    {
        $this->enableImmediateRelworxPayouts();
        Http::fake();
        app(AgentCommissionService::class)->process($this->confirmedPayment('relworx', 1000), 'test');

        $this->withHeaders($this->developerHeaders('minimum-wallet-developer'))
            ->postJson('/api/developer/payout-requests', [
                'amount' => 200,
                'request_token' => 'developer-minimum-200',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'The minimum Relworx payout is 500 UGX.');
        Http::assertNothingSent();
    }

    public function test_roles_cannot_cross_wallet_boundaries(): void
    {
        $this->withHeaders($this->createAdmin('wallet-boundary-owner')['headers'])
            ->getJson('/api/developer/wallet')
            ->assertForbidden();

        $this->withHeaders($this->developerHeaders('wallet-boundary-developer'))
            ->getJson('/api/admin/wallet')
            ->assertForbidden();
    }

    private function confirmedPayment(string $provider, float $amount, ?float $providerNet = null): Payment
    {
        $suffix = (string) random_int(100000, 999999);
        $user = User::create([
            'username' => 'Wallet User',
            'phone' => '07'.$suffix.'00',
            'password_hash' => Hash::make('pass'),
        ]);
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'odds_type' => '2',
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'amount' => $amount,
            'status' => 'active',
        ]);

        return Payment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => $amount,
            'customer_charge_amount' => $amount,
            'provider_net_amount' => $providerNet,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'confirmed',
            'provider' => $provider,
            'payment_reference' => 'ALX-'.$suffix,
            'confirmed_at' => now(),
        ]);
    }

    private function developerHeaders(string $username): array
    {
        $developer = AdminUser::create([
            'username' => $username,
            'role' => 'developer',
            'password_hash' => Hash::make('DevPassword@2026'),
        ]);
        $token = $developer->createToken('wallet-developer', ['role:developer'])->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }

    private function enableImmediateRelworxPayouts(): void
    {
        Config::set('services.payouts.execution_mode', 'immediate');
        Config::set('services.relworx', [
            'api_url' => 'https://payments.relworx.com/api',
            'api_key' => 'relworx-test-key',
            'account_no' => 'RELTEST123',
            'webhook_key' => 'webhook-test-key',
            'webhook_url' => 'https://almaxpredictions.com/api/payments/webhooks/relworx',
            'webhook_tolerance' => 300,
            'timeout' => 10,
            'payouts_enabled' => true,
            'payout_min_amount' => 500,
            'payout_max_amount' => 5000000,
        ]);
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
}
