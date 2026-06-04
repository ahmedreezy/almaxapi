<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
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

    public function test_commission_analytics_counts_only_confirmed_non_failed_tracked_commission(): void
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

        // Confirmed payment whose commission transfer failed: do not count it.
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
            ->assertJsonPath('commission.total_earned', 1000)
            ->assertJsonPath('commission.total_paid', 1000)
            ->assertJsonPath('commission.outstanding', 0)
            ->assertJsonPath('commission.by_status.completed.amount', 1000)
            ->assertJsonPath('commission.by_status.failed.amount', 0)
            ->assertJsonCount(1, 'commission.recent');
    }

    public function test_owner_admin_token_cannot_access_developer_analytics(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/analytics/developer')
            ->assertStatus(403);
    }
}
