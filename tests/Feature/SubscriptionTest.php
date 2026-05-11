<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\VipConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for /api/subscriptions endpoints.
 *
 * Key facts about the subscription API:
 * - POST /api/subscriptions is PUBLIC (no auth required — users pay first, then subscribe)
 * - VALID_COMBOS: '1.5'→weekly only, '2'→daily|weekly, '5'→daily|weekly
 * - Required fields: userId, planType (daily|weekly), paymentMethod (mtn|airtel), phone, oddsType
 * - verify-access takes phone + secretCode (password-style secret, not plan params)
 */
class SubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');

        // Seed VipConfig pricing keys used by SubscriptionController
        $prices = [
            'odds_1.5_weekly_price' => '500',
            'odds_2_daily_price'    => '100',
            'odds_2_weekly_price'   => '500',
            'odds_5_daily_price'    => '150',
            'odds_5_weekly_price'   => '700',
        ];
        foreach ($prices as $key => $value) {
            VipConfig::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    /** Helper: seed a minimal subscription record for state-based tests */
    private function seedSubscription(User $user, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'user_id'        => $user->id,
            'odds_type'      => '2',
            'plan_type'      => 'daily',
            'payment_method' => 'mtn',
            'phone'          => $user->phone,
            'amount'         => 100,
            'status'         => 'pending',
        ], $overrides));
    }

    // ─── Create subscription (PUBLIC route) ──────────────────────────────

    public function test_user_can_create_subscription(): void
    {
        $ctx = $this->createUser('0711000001');

        $response = $this->postJson('/api/subscriptions', [
            'userId'        => $ctx['user']->id,
            'oddsType'      => '2',
            'planType'      => 'daily',
            'paymentMethod' => 'mtn',
            'phone'         => '0711000001',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'user_id', 'odds_type', 'plan_type', 'amount', 'status']);

        $this->assertDatabaseHas('subscriptions', [
            'user_id'   => $ctx['user']->id,
            'odds_type' => '2',
            'plan_type' => 'daily',
        ]);
    }

    public function test_invalid_odds_plan_combo_is_rejected(): void
    {
        $ctx = $this->createUser('0711000002');

        // 1.5 only allows weekly, not daily → should fail with 422 (ValidationException)
        $this->postJson('/api/subscriptions', [
            'userId'        => $ctx['user']->id,
            'oddsType'      => '1.5',
            'planType'      => 'daily',
            'paymentMethod' => 'mtn',
            'phone'         => '0711000002',
        ])->assertStatus(422);
    }

    public function test_all_valid_combos_are_accepted(): void
    {
        // Bypass throttle middleware — this test verifies business logic, not rate limiting
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $validCombos = [
            ['1.5', 'weekly'],
            ['2',   'daily'],
            ['2',   'weekly'],
            ['5',   'daily'],
            ['5',   'weekly'],
        ];

        $i = 10;
        foreach ($validCombos as [$odds, $plan]) {
            $phone = "07110000{$i}";
            $ctx = $this->createUser($phone);
            $i++;
            $this->postJson('/api/subscriptions', [
                'userId'        => $ctx['user']->id,
                'oddsType'      => $odds,
                'planType'      => $plan,
                'paymentMethod' => 'airtel',
                'phone'         => $phone,
            ])->assertStatus(201, "Combo {$odds}/{$plan} should be valid");
        }
    }

    public function test_invalid_odds_type_is_rejected(): void
    {
        $ctx = $this->createUser('0711000030');

        // '3' is not in VALID_COMBOS
        $this->postJson('/api/subscriptions', [
            'userId'        => $ctx['user']->id,
            'oddsType'      => '3',
            'planType'      => 'weekly',
            'paymentMethod' => 'mtn',
            'phone'         => '0711000030',
        ])->assertStatus(422);
    }

    public function test_subscription_validates_required_fields(): void
    {
        // Missing all fields → 422
        $this->postJson('/api/subscriptions', [])->assertStatus(422);
    }

    // ─── Get subscriptions for user ───────────────────────────────────────

    public function test_can_get_subscriptions_for_user(): void
    {
        $ctx = $this->createUser('0712000001');
        $this->seedSubscription($ctx['user']);

        $this->getJson("/api/subscriptions/user/{$ctx['user']->id}")
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'user_id', 'odds_type', 'plan_type', 'status']]);
    }

    public function test_pending_subscription_has_masked_betslip(): void
    {
        $ctx = $this->createUser('0712000002');
        $this->seedSubscription($ctx['user'], [
            'status'       => 'pending',
            'betslip_link' => 'https://secret.link',
            'betslip_code' => 'SECRET-CODE',
        ]);

        $response = $this->getJson("/api/subscriptions/user/{$ctx['user']->id}")
            ->assertStatus(200);

        // betslip should be masked for pending subscriptions
        $sub = $response->json('0');
        $this->assertSame('', $sub['betslip_link']);
        $this->assertSame('', $sub['betslip_code']);
    }

    // ─── Verify access (phone + secretCode) ───────────────────────────────

    public function test_verify_access_returns_404_when_no_active_subscription(): void
    {
        $ctx = $this->createUser('0713000001');
        $this->seedSubscription($ctx['user'], ['status' => 'pending']);

        // pending subscription → no active sub → 404
        $this->postJson('/api/subscriptions/verify-access', [
            'phone'      => $ctx['user']->phone,
            'secretCode' => 'anycode',
        ])->assertStatus(404);
    }

    public function test_verify_access_returns_401_for_wrong_secret_code(): void
    {
        $ctx = $this->createUser('0713000002');
        $this->seedSubscription($ctx['user'], [
            'status'           => 'active',
            'expires_at'       => now()->addHours(20)->toIso8601String(),
            'secret_code_hash' => Hash::make('correct-secret'),
        ]);

        $this->postJson('/api/subscriptions/verify-access', [
            'phone'      => $ctx['user']->phone,
            'secretCode' => 'wrong-secret',
        ])->assertStatus(401);
    }

    public function test_verify_access_succeeds_with_correct_secret(): void
    {
        $ctx = $this->createUser('0713000003');
        $this->seedSubscription($ctx['user'], [
            'status'           => 'active',
            'expires_at'       => now()->addHours(20)->toIso8601String(),
            'secret_code_hash' => Hash::make('correct-secret'),
        ]);

        $this->postJson('/api/subscriptions/verify-access', [
            'phone'      => $ctx['user']->phone,
            'secretCode' => 'correct-secret',
        ])->assertStatus(200)
            ->assertJsonStructure(['subscription', 'user']);
    }

    // ─── Admin: list & update ─────────────────────────────────────────────

    public function test_admin_can_list_subscriptions(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/subscriptions')
            ->assertStatus(200);
    }

    public function test_listing_subscriptions_requires_admin(): void
    {
        $this->getJson('/api/subscriptions')->assertStatus(401);
    }

    public function test_admin_can_activate_subscription(): void
    {
        $admin = $this->createAdmin();
        $user  = User::create(['username' => 'T', 'phone' => '0714000001', 'password_hash' => Hash::make('p')]);
        $sub   = $this->seedSubscription($user, ['status' => 'pending']);

        $this->withHeaders($admin['headers'])
            ->patchJson("/api/subscriptions/{$sub->id}", ['status' => 'active'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('subscriptions', ['id' => $sub->id, 'status' => 'active']);
    }

    // ─── Upload proof ─────────────────────────────────────────────────────

    public function test_user_can_upload_payment_proof(): void
    {
        $ctx  = $this->createUser('0715000001');
        $sub  = $this->seedSubscription($ctx['user']);
        $file = UploadedFile::fake()->image('proof.jpg', 200, 200);

        $this->postJson("/api/subscriptions/{$sub->id}/proof", ['proof' => $file])
            ->assertStatus(200)
            ->assertJsonStructure(['id', 'proof_url']);
    }
}
