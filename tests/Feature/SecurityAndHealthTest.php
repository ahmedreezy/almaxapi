<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Tests for security headers, health endpoint, livescores 404,
 * and general middleware behaviour.
 */
class SecurityAndHealthTest extends TestCase
{
    // ─── Health check ─────────────────────────────────────────────────────

    public function test_health_endpoint_is_accessible(): void
    {
        $this->getJson('/api/health')
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    // ─── Livescores ───────────────────────────────────────────────────────

    public function test_livescores_returns_404(): void
    {
        $this->getJson('/api/livescores')->assertStatus(404);
        $this->postJson('/api/livescores')->assertStatus(404);
    }

    // ─── Security headers ─────────────────────────────────────────────────

    public function test_security_headers_are_present(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy');
    }

    public function test_server_header_is_removed(): void
    {
        // The SecurityHeaders middleware strips X-Powered-By and Server
        $response = $this->getJson('/api/health');
        $this->assertNull($response->headers->get('X-Powered-By'));
    }

    // ─── JSON error responses ────────────────────────────────────────────

    public function test_404_returns_json(): void
    {
        $this->getJson('/api/this-route-does-not-exist')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_method_not_allowed_returns_json_405(): void
    {
        // health only accepts GET — POST should be 405
        $this->postJson('/api/health')
            ->assertStatus(405);
    }

    public function test_validation_error_returns_422_with_errors_key(): void
    {
        // Register with missing fields → validation fails
        // bootstrap/app.php custom handler returns {"error":"...","errors":{...}}
        $this->postJson('/api/users', [])
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors']);
    }

    // ─── Rate limiting ────────────────────────────────────────────────────

    public function test_auth_endpoint_rate_limited_after_5_attempts(): void
    {
        // The 'auth' rate limiter allows 5 requests per minute per IP
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'username' => 'admin',
                'password' => 'wrongpassword',
            ]);
        }

        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'wrongpassword',
        ]);

        // After 5 failed attempts the rate limiter kicks in (429)
        $response->assertStatus(429);
    }

    // ─── CORS ─────────────────────────────────────────────────────────────

    public function test_cors_headers_present_for_allowed_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://almaxpredictions.com',
        ])->getJson('/api/health');

        $this->assertNotNull(
            $response->headers->get('Access-Control-Allow-Origin'),
            'CORS header should be present for allowed origin'
        );
    }
}
