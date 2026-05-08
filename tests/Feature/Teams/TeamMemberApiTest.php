<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;

use function Pest\Laravel\getJson;

/**
 * Helper: build a team with a known creator user for these tests.
 *
 * @return array{user: User, game: Game, player: Player, team: Team}
 */
function makeTeamForCreator(): array
{
    $user   = User::factory()->create();
    $game   = Game::factory()->create();
    $player = Player::factory()->for($user)->for($game)->create();
    $team   = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => $player->id,
    ]);

    return ['user' => $user, 'game' => $game, 'player' => $player, 'team' => $team];
}

// ---------------------------------------------------------------------------
// GET /api/teams/{team}/members
// ---------------------------------------------------------------------------

test('index lists members publicly with optional active filter', function () {
    $team = Team::factory()->create();
    TeamMember::factory()->for($team)->count(2)->create();
    TeamMember::factory()->for($team)->left()->create();

    getJson("/api/teams/{$team->id}/members")->assertOk()->assertJsonCount(3, 'data');
    getJson("/api/teams/{$team->id}/members?active=1")->assertOk()->assertJsonCount(2, 'data');
});

// ---------------------------------------------------------------------------
// POST /api/teams/{team}/members
// ---------------------------------------------------------------------------

test('the creator can add a member', function () {
    ['user' => $owner, 'game' => $game, 'team' => $team] = makeTeamForCreator();
    $newPlayer = Player::factory()->for($game)->create();

    $this->actingAs($owner)
        ->postJson("/api/teams/{$team->id}/members", [
            'player_id' => $newPlayer->id,
            'role'      => 'player',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.player_id', $newPlayer->id);
});

test('store 422s when player belongs to a different game', function () {
    ['user' => $owner, 'team' => $team] = makeTeamForCreator();
    $otherGamePlayer = Player::factory()->create();

    $this->actingAs($owner)
        ->postJson("/api/teams/{$team->id}/members", [
            'player_id' => $otherGamePlayer->id,
            'role'      => 'player',
        ])
        ->assertStatus(422);
});

test('store 422s when adding a player who is already on the active roster', function () {
    ['user' => $owner, 'game' => $game, 'team' => $team] = makeTeamForCreator();
    $player = Player::factory()->for($game)->create();
    TeamMember::factory()->for($team)->create(['player_id' => $player->id]);

    $this->actingAs($owner)
        ->postJson("/api/teams/{$team->id}/members", [
            'player_id' => $player->id,
            'role'      => 'player',
        ])
        ->assertStatus(422);
});

test('a stranger cannot add a member', function () {
    $team = Team::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/teams/{$team->id}/members", [
            'player_id' => Player::factory()->create()->id,
            'role'      => 'player',
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// PATCH /api/teams/{team}/members/{member}
// ---------------------------------------------------------------------------

test('the creator can change a member role', function () {
    ['user' => $owner, 'team' => $team] = makeTeamForCreator();
    $member = TeamMember::factory()->for($team)->create(['role' => 'player']);

    $this->actingAs($owner)
        ->patchJson("/api/teams/{$team->id}/members/{$member->id}", ['role' => 'captain'])
        ->assertOk()
        ->assertJsonPath('data.role', 'captain');
});

test('the player whose membership it is can self-leave by setting left_at', function () {
    $playerUser = User::factory()->create();
    $game       = Game::factory()->create();
    $playerP    = Player::factory()->for($playerUser)->for($game)->create();
    $team       = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => Player::factory()->for($game)->create()->id,
    ]);
    $member = TeamMember::factory()->create([
        'team_id'   => $team->id,
        'player_id' => $playerP->id,
        'role'      => 'player',
    ]);

    $this->actingAs($playerUser)
        ->patchJson("/api/teams/{$team->id}/members/{$member->id}", ['left_at' => now()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.left_at', fn ($v) => ! is_null($v));
});

test('a self-leaving player cannot also change their role (403)', function () {
    $playerUser = User::factory()->create();
    $game       = Game::factory()->create();
    $playerP    = Player::factory()->for($playerUser)->for($game)->create();
    $team       = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => Player::factory()->for($game)->create()->id,
    ]);
    $member = TeamMember::factory()->create([
        'team_id'   => $team->id,
        'player_id' => $playerP->id,
    ]);

    $this->actingAs($playerUser)
        ->patchJson("/api/teams/{$team->id}/members/{$member->id}", [
            'role'    => 'captain',
            'left_at' => now()->toIso8601String(),
        ])
        ->assertForbidden();
});

test('a stranger cannot patch a team member', function () {
    $team   = Team::factory()->create();
    $member = TeamMember::factory()->for($team)->create();

    $this->actingAs(User::factory()->create())
        ->patchJson("/api/teams/{$team->id}/members/{$member->id}", ['role' => 'captain'])
        ->assertForbidden();
});

test('cross-team tampering returns 404', function () {
    $teamA  = Team::factory()->create();
    $teamB  = Team::factory()->create();
    $member = TeamMember::factory()->for($teamB)->create();

    // Owner of team A tries to patch a member of team B via team A's URL
    $ownerA = User::factory()->create();
    $teamA->update(['created_by_player_id' => Player::factory()->for($teamA->game)->for($ownerA)->create()->id]);

    $this->actingAs($ownerA)
        ->patchJson("/api/teams/{$teamA->id}/members/{$member->id}", ['role' => 'captain'])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// DELETE /api/teams/{team}/members/{member}
// ---------------------------------------------------------------------------

test('the creator can hard-remove a roster row', function () {
    ['user' => $owner, 'team' => $team] = makeTeamForCreator();
    $member = TeamMember::factory()->for($team)->create();

    $this->actingAs($owner)
        ->deleteJson("/api/teams/{$team->id}/members/{$member->id}")
        ->assertOk();

    expect(TeamMember::find($member->id))->toBeNull();
});

test('a captain (not creator) cannot hard-delete roster rows (use PATCH left_at)', function () {
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
    $other = TeamMember::factory()->for($team)->create();

    $this->actingAs($captainUser)
        ->deleteJson("/api/teams/{$team->id}/members/{$other->id}")
        ->assertForbidden();
});
