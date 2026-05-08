<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// ---------------------------------------------------------------------------
// GET /api/players
// ---------------------------------------------------------------------------

test('index lists players publicly and supports game_id + user_id filters', function () {
    $game1 = Game::factory()->create();
    $game2 = Game::factory()->create();
    $user  = User::factory()->create();

    Player::factory()->for($user)->for($game1)->create();
    Player::factory()->for($game1)->create(); // different user, same game
    Player::factory()->for($user)->for($game2)->create();

    getJson('/api/players')->assertOk()->assertJsonCount(3, 'data');
    getJson("/api/players?game_id={$game1->id}")->assertOk()->assertJsonCount(2, 'data');
    getJson("/api/players?user_id={$user->id}")->assertOk()->assertJsonCount(2, 'data');
});

test('show returns the requested player', function () {
    $player = Player::factory()->create();

    getJson("/api/players/{$player->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $player->id)
        ->assertJsonPath('data.gamertag', $player->gamertag);
});

// ---------------------------------------------------------------------------
// POST /api/players
// ---------------------------------------------------------------------------

test('store rejects unauthenticated requests', function () {
    postJson('/api/players', ['game_id' => 1, 'gamertag' => 'X'])->assertUnauthorized();
});

test('an authenticated user can create their own player profile', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/players', [
            'game_id'  => $game->id,
            'gamertag' => 'AceRunner',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.game_id', $game->id)
        ->assertJsonPath('data.gamertag', 'AceRunner');
});

test('store rejects a second profile for the same game by the same user', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create();
    Player::factory()->for($user)->for($game)->create();

    $this->actingAs($user)
        ->postJson('/api/players', ['game_id' => $game->id, 'gamertag' => 'DupSecond'])
        ->assertStatus(422);
});

test('store rejects a gamertag already taken in the same game', function () {
    $game = Game::factory()->create();
    Player::factory()->for($game)->create(['gamertag' => 'TakenTag']);

    $this->actingAs(User::factory()->create())
        ->postJson('/api/players', ['game_id' => $game->id, 'gamertag' => 'TakenTag'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['gamertag']);
});

// ---------------------------------------------------------------------------
// PATCH + DELETE
// ---------------------------------------------------------------------------

test('the owner can patch their own player profile', function () {
    $player = Player::factory()->create(['rank_or_tier' => 'Silver']);

    $this->actingAs($player->user)
        ->patchJson("/api/players/{$player->id}", ['rank_or_tier' => 'Gold'])
        ->assertOk()
        ->assertJsonPath('data.rank_or_tier', 'Gold');
});

test('a non-owner cannot patch a player profile', function () {
    $player   = Player::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patchJson("/api/players/{$player->id}", ['rank_or_tier' => 'Hijacked'])
        ->assertForbidden();
});

test('the owner can delete their own player profile', function () {
    $player = Player::factory()->create();

    $this->actingAs($player->user)
        ->deleteJson("/api/players/{$player->id}")
        ->assertOk();

    expect(Player::find($player->id))->toBeNull();
});
