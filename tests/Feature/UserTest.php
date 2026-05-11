<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests for user registration, login, and admin user management.
 */
class UserTest extends TestCase
{
    // ─── Registration ────────────────────────────────────────────────────

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/users', [
            'username' => 'John Doe',
            'phone'    => '0700123456',
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'username', 'phone', 'createdAt', 'token'])
            ->assertJson(['phone' => '0700123456']);

        $this->assertDatabaseHas('users', ['phone' => '0700123456']);
    }

    public function test_registration_fails_with_duplicate_phone(): void
    {
        User::create([
            'username'      => 'Existing',
            'phone'         => '0700123456',
            'password_hash' => Hash::make('pass123'),
        ]);

        $this->postJson('/api/users', [
            'username' => 'John',
            'phone'    => '0700123456',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_registration_requires_all_fields(): void
    {
        $this->postJson('/api/users', [])->assertStatus(422);
        $this->postJson('/api/users', ['username' => 'A', 'phone' => '07'])->assertStatus(422);
    }

    public function test_registration_requires_min_6_char_password(): void
    {
        $this->postJson('/api/users', [
            'username' => 'John',
            'phone'    => '0700000001',
            'password' => '12345',
        ])->assertStatus(422);
    }

    public function test_registration_password_is_not_returned(): void
    {
        $response = $this->postJson('/api/users', [
            'username' => 'John',
            'phone'    => '0700000001',
            'password' => 'password123',
        ]);

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('password_hash', $response->json());
        $this->assertArrayNotHasKey('password', $response->json());
    }

    // ─── Login ───────────────────────────────────────────────────────────

    public function test_user_can_login(): void
    {
        User::create([
            'username'      => 'Alice',
            'phone'         => '0700111111',
            'password_hash' => Hash::make('mypassword'),
        ]);

        $response = $this->postJson('/api/users/login', [
            'phone'    => '0700111111',
            'password' => 'mypassword',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['id', 'username', 'phone', 'createdAt', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::create([
            'username'      => 'Bob',
            'phone'         => '0700222222',
            'password_hash' => Hash::make('correctpass'),
        ]);

        $this->postJson('/api/users/login', [
            'phone'    => '0700222222',
            'password' => 'wrongpass',
        ])->assertStatus(422);
    }

    public function test_login_fails_for_unknown_phone(): void
    {
        $this->postJson('/api/users/login', [
            'phone'    => '0700000000',
            'password' => 'anypass',
        ])->assertStatus(422);
    }

    // ─── Find by phone ───────────────────────────────────────────────────

    public function test_can_find_user_by_phone(): void
    {
        User::create([
            'username'      => 'Carol',
            'phone'         => '0700333333',
            'password_hash' => Hash::make('pass'),
        ]);

        $this->getJson('/api/users/by-phone/0700333333')
            ->assertStatus(200)
            ->assertJsonPath('phone', '0700333333');
    }

    public function test_find_by_phone_returns_404_for_unknown(): void
    {
        $this->getJson('/api/users/by-phone/9999999999')
            ->assertStatus(404);
    }

    // ─── Admin: list and delete ──────────────────────────────────────────

    public function test_admin_can_list_users(): void
    {
        $ctx = $this->createAdmin();
        User::create(['username' => 'X', 'phone' => '0701', 'password_hash' => Hash::make('p')]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/users')
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'username', 'phone']]);
    }

    public function test_listing_users_requires_admin_token(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
    }

    public function test_admin_can_delete_user(): void
    {
        $ctx  = $this->createAdmin();
        $user = User::create(['username' => 'Del', 'phone' => '0702', 'password_hash' => Hash::make('p')]);

        $this->withHeaders($ctx['headers'])
            ->deleteJson("/api/users/{$user->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_user_token_cannot_list_users(): void
    {
        $ctx = $this->createUser();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/users')
            ->assertStatus(403);
    }

    // ─── bcrypt $2b$ compatibility ───────────────────────────────────────

    public function test_existing_nodejs_bcryptjs_hashes_are_compatible(): void
    {
        // bcryptjs generates $2b$ prefix; PHP's password_verify treats it as $2y$
        // This test verifies the existing user passwords from Node.js still work
        $nodejsHash = password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 12]);
        // Replace $2y$ with $2b$ to simulate what Node.js stored
        $nodejsHash = str_replace('$2y$', '$2b$', $nodejsHash);

        User::create([
            'username'      => 'Migrated',
            'phone'         => '0703000000',
            'password_hash' => $nodejsHash,
        ]);

        $this->postJson('/api/users/login', [
            'phone'    => '0703000000',
            'password' => 'testpassword',
        ])->assertStatus(200);
    }
}
