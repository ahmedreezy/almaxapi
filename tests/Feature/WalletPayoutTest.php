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

        $response = $this->withHeaders($headers)->postJson('/api/developer/payout-requests', ['amount' => 600]);
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
                'destination_msisdn' => '+256799999999',
            ])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->postJson('/api/developer/payout-requests', [
                'amount' => 500,
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
}
