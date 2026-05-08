<?php

use App\Models\Game;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// ---------------------------------------------------------------------------
// GET /api/teams
// ---------------------------------------------------------------------------

test('index lists teams publicly with optional filters', function () {
    $g1 = Game::factory()->create();
    $g2 = Game::factory()->create();
    $org = Organization::factory()->create();

    Team::factory()->create([
        'game_id'              => $g1->id,
        'created_by_player_id' => Player::factory()->for($g1)->create()->id,
    ]);
    Team::factory()->forOrganization($org)->create([
        'game_id'              => $g1->id,
        'created_by_player_id' => Player::factory()->for($g1)->create()->id,
    ]);
    Team::factory()->create([
        'game_id'              => $g2->id,
        'created_by_player_id' => Player::factory()->for($g2)->create()->id,
    ]);

    getJson('/api/teams')->assertOk()->assertJsonCount(3, 'data');
    getJson("/api/teams?game_id={$g1->id}")->assertOk()->assertJsonCount(2, 'data');
    getJson("/api/teams?organization_id={$org->id}")->assertOk()->assertJsonCount(1, 'data');
});

test('show returns the requested team', function () {
    $team = Team::factory()->create();

    getJson("/api/teams/{$team->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $team->id);
});

// ---------------------------------------------------------------------------
// POST /api/teams
// ---------------------------------------------------------------------------

test('store rejects unauthenticated requests', function () {
    postJson('/api/teams', [])->assertUnauthorized();
});

test('a user with a player can create a team using that player as creator', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create();
    $player = Player::factory()->for($user)->for($game)->create();

    $this->actingAs($user)
        ->postJson('/api/teams', [
            'game_id'              => $game->id,
            'name'                 => 'Apex Predators',
            'tag'                  => 'APX',
            'created_by_player_id' => $player->id,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Apex Predators')
        ->assertJsonPath('data.created_by_player_id', $player->id);
});

test('store rejects naming a creator player you do not own (403)', function () {
    $user           = User::factory()->create();
    $someoneElse    = Player::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/teams', [
            'game_id'              => $someoneElse->game_id,
            'name'                 => 'Hijacked',
            'created_by_player_id' => $someoneElse->id,
        ])
        ->assertForbidden();
});

test('store 422s if creator player is for a different game', function () {
    $user      = User::factory()->create();
    $playerGame = Game::factory()->create();
    $teamGame  = Game::factory()->create();
    $player    = Player::factory()->for($user)->for($playerGame)->create();

    $this->actingAs($user)
        ->postJson('/api/teams', [
            'game_id'              => $teamGame->id,
            'name'                 => 'Wrong Game',
            'created_by_player_id' => $player->id,
        ])
        ->assertStatus(422);
});

test('store 422s on duplicate team name within the same game', function () {
    $user   = User::factory()->create();
    $game   = Game::factory()->create();
    $player = Player::factory()->for($user)->for($game)->create();
    Team::factory()->create([
        'game_id'              => $game->id,
        'name'                 => 'Taken',
        'created_by_player_id' => Player::factory()->for($game)->create()->id,
    ]);

    $this->actingAs($user)
        ->postJson('/api/teams', [
            'game_id'              => $game->id,
            'name'                 => 'Taken',
            'created_by_player_id' => $player->id,
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// PATCH /api/teams/{team}
// ---------------------------------------------------------------------------

test('the team creator can patch the team', function () {
    $user   = User::factory()->create();
    $game   = Game::factory()->create();
    $player = Player::factory()->for($user)->for($game)->create();
    $team   = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => $player->id,
    ]);

    $this->actingAs($user)
        ->patchJson("/api/teams/{$team->id}", ['tag' => 'NEW'])
        ->assertOk()
        ->assertJsonPath('data.tag', 'NEW');
});

test('an active captain (not creator) can patch the team', function () {
    $captainUser = User::factory()->create();
    $game        = Game::factory()->create();
    $captainP    = Player::factory()->for($captainUser)->for($game)->create();

    $team = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => Player::factory()->for($game)->create()->id,
    ]);
    TeamMember::factory()->captain()->create([
        'team_id'   => $team->id,
        'player_id' => $captainP->id,
    ]);

    $this->actingAs($captainUser)
        ->patchJson("/api/teams/{$team->id}", ['name' => 'Renamed Squad'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Squad');
});

test('a stranger cannot patch the team', function () {
    $team = Team::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patchJson("/api/teams/{$team->id}", ['name' => 'Hijacked'])
        ->assertForbidden();
});

test('a superadmin can patch any team', function () {
    $team = Team::factory()->create();

    $this->actingAs(User::factory()->superadmin()->create())
        ->patchJson("/api/teams/{$team->id}", ['name' => 'Admin Renamed'])
        ->assertOk();
});

// ---------------------------------------------------------------------------
// DELETE /api/teams/{team}
// ---------------------------------------------------------------------------

test('captain alone cannot delete a team (403) — only creator/superadmin', function () {
    $captainUser = User::factory()->create();
    $game        = Game::factory()->create();
    $captainP    = Player::factory()->for($captainUser)->for($game)->create();
    $team        = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => Player::factory()->for($game)->create()->id,
    ]);
    TeamMember::factory()->captain()->create([
        'team_id'   => $team->id,
        'player_id' => $captainP->id,
    ]);

    $this->actingAs($captainUser)
        ->deleteJson("/api/teams/{$team->id}")
        ->assertForbidden();
});

test('the creator can soft-delete the team', function () {
    $user   = User::factory()->create();
    $game   = Game::factory()->create();
    $player = Player::factory()->for($user)->for($game)->create();
    $team   = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => $player->id,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/teams/{$team->id}")
        ->assertOk();

    expect(Team::find($team->id))->toBeNull();
    expect(Team::withTrashed()->find($team->id))->not->toBeNull();
});
