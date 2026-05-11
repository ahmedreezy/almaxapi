<?php

namespace Tests\Feature;

use App\Models\AlmaxPrediction;
use Tests\TestCase;

/**
 * Tests for /api/almax-predictions — public read, admin write.
 */
class AlmaxPredictionTest extends TestCase
{
    /**
     * AlmaxPrediction controller uses: home, away, competition, kickoff, tip (required);
     *   odds, result (optional).
     */
    private function validPred(array $overrides = []): array
    {
        return array_merge([
            'home'        => 'Man Utd',
            'away'        => 'Arsenal',
            'competition' => 'EPL',
            'kickoff'     => '2026-05-10 15:00',
            'tip'         => 'Over 2.5',
        ], $overrides);
    }

    public function test_anyone_can_list_predictions(): void
    {
        AlmaxPrediction::create($this->validPred());

        $this->getJson('/api/almax-predictions')
            ->assertStatus(200)
            ->assertJsonStructure([['id', 'home', 'away', 'tip']]);
    }

    public function test_listing_is_public(): void
    {
        $this->getJson('/api/almax-predictions')->assertStatus(200);
    }

    public function test_admin_can_create_prediction(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->postJson('/api/almax-predictions', $this->validPred(['odds' => '1.80']))
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'home', 'away', 'tip']);
    }

    public function test_creating_prediction_requires_admin(): void
    {
        $this->postJson('/api/almax-predictions', $this->validPred())->assertStatus(401);
    }

    public function test_user_token_cannot_create_prediction(): void
    {
        $ctx = $this->createUser();

        $this->withHeaders($ctx['headers'])
            ->postJson('/api/almax-predictions', $this->validPred())
            ->assertStatus(403);
    }

    public function test_admin_can_update_prediction(): void
    {
        $ctx  = $this->createAdmin();
        $pred = AlmaxPrediction::create($this->validPred());

        $this->withHeaders($ctx['headers'])
            ->patchJson("/api/almax-predictions/{$pred->id}", ['tip' => 'Draw'])
            ->assertStatus(200)
            ->assertJsonPath('tip', 'Draw');
    }

    public function test_admin_can_delete_prediction(): void
    {
        $ctx  = $this->createAdmin();
        $pred = AlmaxPrediction::create($this->validPred());

        $this->withHeaders($ctx['headers'])
            ->deleteJson("/api/almax-predictions/{$pred->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('almax_predictions', ['id' => $pred->id]);
    }

    public function test_prediction_returns_404_for_unknown_update(): void
    {
        $ctx = $this->createAdmin();

        $this->withHeaders($ctx['headers'])
            ->patchJson('/api/almax-predictions/99999', ['tip' => 'X'])
            ->assertStatus(404);
    }
}
