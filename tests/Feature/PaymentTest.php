<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests for /api/payments — admin read only.
 */
class PaymentTest extends TestCase
{
    private function seedPayment(): Payment
    {
        $user = User::create([
            'username'      => 'PayUser',
            'phone'         => '0720000001',
            'password_hash' => Hash::make('pass'),
        ]);
        $sub = Subscription::create([
            'user_id'        => $user->id,
            'odds_type'      => '2',
            'plan_type'      => 'daily',
            'payment_method' => 'mtn',
            'phone'          => $user->phone,
            'amount'         => 100,
            'status'         => 'pending',
        ]);
        return Payment::create([
            'subscription_id' => $sub->id,
            'user_id'         => $user->id,
            'amount'          => 100,
            'plan_type'       => 'daily',
            'payment_method'  => 'mtn',
            'phone'           => $user->phone,
            'status'          => 'pending',
        ]);
    }

    public function test_admin_can_list_payments(): void
    {
        $ctx = $this->createAdmin();
        $this->seedPayment();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/payments')
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'amount', 'status']]);
    }

    public function test_listing_payments_requires_admin(): void
    {
        $this->getJson('/api/payments')->assertStatus(401);
    }

    public function test_user_token_cannot_list_payments(): void
    {
        $ctx = $this->createUser();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/payments')
            ->assertStatus(403);
    }

    public function test_admin_can_view_single_payment(): void
    {
        $ctx     = $this->createAdmin();
        $payment = $this->seedPayment();

        $this->withHeaders($ctx['headers'])
            ->getJson("/api/payments/{$payment->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $payment->id);
    }

    public function test_single_payment_returns_404_for_unknown(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/payments/99999')
            ->assertStatus(404);
    }
}
