<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeveloperAnalyticsTest extends TestCase
{
    private function developerHeaders(): array
    {
        $dev = AdminUser::create([
            'username'      => 'almaxdev-test',
            'role'          => 'developer',
            'password_hash' => Hash::make('DevPassword@2026'),
        ]);

        $token = $dev->createToken('dev-token', ['role:developer'])->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_commission_analytics_includes_failed_commissions_for_retry_visibility(): void
    {
        Config::set('services.mobile_money.agent_commission.enabled', true);
        Config::set('services.mobile_money.agent_commission.ratio', 0.1);

        $user = User::create([
            'username'      => 'Commission Member',
            'phone'         => '0700777001',
            'password_hash' => Hash::make('password123'),
        ]);

        $sub = Subscription::create([
            'user_id'        => $user->id,
            'odds_type'      => '2',
            'plan_type'      => 'daily',
            'payment_method' => 'airtel',
            'phone'          => $user->phone,
            'amount'         => 10000,
            'status'         => 'active',
        ]);

        Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 10000,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'confirmed',
            'agent_commission_amount' => 1000,
            'agent_commission_ratio' => 0.1,
            'agent_commission_status' => 'sent',
        ]);

        // Historical confirmed payment from before commission tracking existed.
        Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 520000,
            'plan_type' => 'weekly',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'confirmed',
        ]);

        // Confirmed payment whose commission transfer failed: keep it visible so it can be retried.
        Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 20000,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'confirmed',
            'agent_commission_amount' => 2000,
            'agent_commission_ratio' => 0.1,
            'agent_commission_status' => 'failed',
        ]);

        // Failed payment rows never contribute to commission totals.
        Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 30000,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'failed',
            'agent_commission_amount' => 3000,
            'agent_commission_ratio' => 0.1,
            'agent_commission_status' => 'sent',
        ]);

        $response = $this->withHeaders($this->developerHeaders())
            ->getJson('/api/analytics/developer')
            ->assertStatus(200);

        $response->assertJsonPath('finance.total_revenue', 550000)
            ->assertJsonPath('commission.ratio', 0.1)
            ->assertJsonPath('commission.total_earned', 3000)
            ->assertJsonPath('commission.total_paid', 1000)
            ->assertJsonPath('commission.outstanding', 2000)
            ->assertJsonPath('commission.by_status.completed.amount', 1000)
            ->assertJsonPath('commission.by_status.failed.amount', 2000)
            ->assertJsonCount(2, 'commission.recent');
    }

    public function test_owner_admin_token_cannot_access_developer_analytics(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/analytics/developer')
            ->assertStatus(403);
    }

    public function test_developer_can_retry_failed_agent_commission(): void
    {
        Config::set('services.mobile_money.api_url', 'https://my.jpesa.com/api/');
        Config::set('services.mobile_money.api_key', 'source-key');
        Config::set('services.mobile_money.callback_url', 'https://example.test/api/payments/webhook');
        Config::set('services.mobile_money.agent_commission', [
            'enabled'          => true,
            'ratio'            => 0.1,
            'recipient_type'   => 'business',
            'recipient_email'  => 'prof.markdemo@gmail.com',
            'recipient_mobile' => '0704045918',
            'transfer_action'  => 'debit',
            'transfer_pt'      => 'gwallet',
            'currency'         => 'UGX',
        ]);

        Http::fake(fn () => Http::response(['status' => 'success', 'tid' => 'RETRY-COM-123'], 200));

        $user = User::create([
            'username'      => 'Retry Commission Member',
            'phone'         => '0700777002',
            'password_hash' => Hash::make('password123'),
        ]);

        $sub = Subscription::create([
            'user_id'        => $user->id,
            'odds_type'      => '2',
            'plan_type'      => 'daily',
            'payment_method' => 'airtel',
            'phone'          => $user->phone,
            'amount'         => 20000,
            'status'         => 'active',
            'payment_reference' => 'ALX-RETRY-TEST',
        ]);

        $payment = Payment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 20000,
            'plan_type' => 'daily',
            'payment_method' => 'airtel',
            'phone' => $user->phone,
            'status' => 'confirmed',
            'payment_reference' => 'ALX-RETRY-TEST',
            'transaction_id' => 'ALX-RETRY-TEST',
            'agent_commission_amount' => 2000,
            'agent_commission_ratio' => 0.1,
            'agent_commission_status' => 'failed',
            'agent_commission_error' => '[[E000008]] Account has insufficient balance',
        ]);

        $this->withHeaders($this->developerHeaders())
            ->postJson("/api/analytics/developer/payments/{$payment->id}/retry-commission")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment.agent_commission_status', 'completed');

        $payment->refresh();
        $this->assertSame('sent', $payment->agent_commission_status);
        $this->assertSame('RETRY-COM-123', $payment->agent_commission_transaction_id);
        $this->assertNull($payment->agent_commission_error);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, '<action>debit</action>')
                && str_contains($body, '<pt>gwallet</pt>')
                && str_contains($body, '<business>prof.markdemo@gmail.com</business>')
                && str_contains($body, '<amount>2000</amount>');
        });
    }
}
