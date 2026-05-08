<?php

use App\Models\Game;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// ---------------------------------------------------------------------------
// GET /api/games  +  GET /api/games/{id}
// ---------------------------------------------------------------------------

test('index returns active games by default and accepts include_inactive', function () {
    Game::factory()->create(['name' => 'Active Game']);
    Game::factory()->inactive()->create(['name' => 'Inactive Game']);

    getJson('/api/games')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Active Game');

    getJson('/api/games?include_inactive=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('show returns the requested game', function () {
    $game = Game::factory()->create();

    getJson("/api/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $game->id)
        ->assertJsonPath('data.name', $game->name);
});

// ---------------------------------------------------------------------------
// POST /api/games  (system_manager + superadmin only)
// ---------------------------------------------------------------------------

test('store rejects unauthenticated requests', function () {
    postJson('/api/games', ['name' => 'X', 'slug' => 'x'])->assertUnauthorized();
});

test('store rejects users without games.manage permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/games', ['name' => 'Valorant', 'slug' => 'valorant'])
        ->assertForbidden();
});

test('a system_manager can create a game', function () {
    $manager = User::factory()->systemManager()->create();

    $this->actingAs($manager)
        ->postJson('/api/games', [
            'name' => 'Valorant',
            'slug' => 'valorant',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.slug', 'valorant')
        ->assertJsonPath('data.is_active', true);
});

test('store rejects duplicate slugs', function () {
    Game::factory()->create(['slug' => 'taken']);
    $manager = User::factory()->systemManager()->create();

    $this->actingAs($manager)
        ->postJson('/api/games', ['name' => 'X', 'slug' => 'taken'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);
});

// ---------------------------------------------------------------------------
// PATCH + DELETE /api/games/{id}
// ---------------------------------------------------------------------------

test('a system_manager can patch a game', function () {
    $game    = Game::factory()->create(['is_active' => true]);
    $manager = User::factory()->systemManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/games/{$game->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

test('a system_manager can delete a game', function () {
    $game    = Game::factory()->create();
    $manager = User::factory()->systemManager()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/games/{$game->id}")
        ->assertOk();

    expect(Game::find($game->id))->toBeNull();
});
