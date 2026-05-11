<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests for POST /api/auth/login and POST /api/auth/change-password
 */
class AuthTest extends TestCase
{
    // ─── Login ────────────────────────────────────────────────────────────

    public function test_admin_can_login_with_valid_credentials(): void
    {
        AdminUser::create([
            'username'      => 'admin',
            'password_hash' => Hash::make('secret123456'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'secret123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'admin' => ['id', 'username']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        AdminUser::create([
            'username'      => 'admin',
            'password_hash' => Hash::make('secret123456'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_fails_with_unknown_username(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'nobody',
            'password' => 'somepassword',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_returns_same_error_for_wrong_user_and_wrong_password(): void
    {
        // Both cases must return 422 — no username enumeration
        AdminUser::create([
            'username'      => 'admin',
            'password_hash' => Hash::make('secret123456'),
        ]);

        $r1 = $this->postJson('/api/auth/login', ['username' => 'admin',   'password' => 'wrong']);
        $r2 = $this->postJson('/api/auth/login', ['username' => 'nobody',  'password' => 'wrong']);

        $r1->assertStatus(422);
        $r2->assertStatus(422);
        $this->assertEquals($r1->json('message'), $r2->json('message'));
    }

    public function test_login_validates_required_fields(): void
    {
        $this->postJson('/api/auth/login', [])->assertStatus(422);
        $this->postJson('/api/auth/login', ['username' => 'a'])->assertStatus(422);
    }

    // ─── Change password ─────────────────────────────────────────────────

    public function test_admin_can_change_password(): void
    {
        $ctx = $this->createAdmin();

        $response = $this->withHeaders($ctx['headers'])
            ->postJson('/api/auth/change-password', [
                'currentPassword' => 'TestPassword@2024',
                'newPassword'     => 'NewStrongPassword@2025',
            ]);

        $response->assertStatus(200)->assertJson(['message' => 'Password updated successfully.']);
    }

    public function test_change_password_fails_with_wrong_current_password(): void
    {
        $ctx = $this->createAdmin();

        $response = $this->withHeaders($ctx['headers'])
            ->postJson('/api/auth/change-password', [
                'currentPassword' => 'wrongpassword',
                'newPassword'     => 'NewStrongPassword@2025',
            ]);

        $response->assertStatus(422);
    }

    public function test_change_password_requires_auth(): void
    {
        $this->postJson('/api/auth/change-password', [
            'currentPassword' => 'any',
            'newPassword'     => 'NewStrongPassword@2025',
        ])->assertStatus(401);
    }

    public function test_change_password_requires_min_12_chars(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->postJson('/api/auth/change-password', [
                'currentPassword' => 'TestPassword@2024',
                'newPassword'     => 'short',
            ])
            ->assertStatus(422);
    }

    public function test_user_token_cannot_access_change_password(): void
    {
        $ctx = $this->createUser();

        $this->withHeaders($ctx['headers'])
            ->postJson('/api/auth/change-password', [
                'currentPassword' => 'TestPassword@2024',
                'newPassword'     => 'NewStrongPassword@2025',
            ])
            ->assertStatus(403);  // admin endpoint — user token gets 403
    }
}
