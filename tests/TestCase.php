<?php

namespace Tests;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Flush in-memory cache between tests so rate limiters reset
        Cache::flush();
    }

    /**
     * Create and return an admin user with a valid Sanctum token.
     * Returns ['admin' => AdminUser, 'token' => string, 'headers' => array]
     */
    protected function createAdmin(string $username = 'testadmin', string $password = 'TestPassword@2024'): array
    {
        $admin = AdminUser::create([
            'username'      => $username,
            'password_hash' => Hash::make($password),
        ]);

        $token = $admin->createToken('admin-token', ['role:admin'])->plainTextToken;

        return [
            'admin'   => $admin,
            'token'   => $token,
            'headers' => ['Authorization' => "Bearer {$token}"],
        ];
    }

    /**
     * Create and return a regular user with a valid Sanctum token.
     */
    protected function createUser(string $phone = '0700000001', string $password = 'password123'): array
    {
        $user = User::create([
            'username'      => 'Test User',
            'phone'         => $phone,
            'password_hash' => Hash::make($password),
        ]);

        $token = $user->createToken('user-token', ['role:user'])->plainTextToken;

        return [
            'user'    => $user,
            'token'   => $token,
            'headers' => ['Authorization' => "Bearer {$token}"],
        ];
    }
}
