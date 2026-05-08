<?php

use App\Models\Game;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a team belongs to a game and a creator player; org is optional', function () {
    $team = Team::factory()->create();

    expect($team->game)->toBeInstanceOf(Game::class);
    expect($team->creator)->toBeInstanceOf(Player::class);
    expect($team->organization)->toBeNull();
});

test('a team can be affiliated with an organisation', function () {
    $org  = Organization::factory()->create();
    $team = Team::factory()->forOrganization($org)->create();

    expect($team->organization)->toBeInstanceOf(Organization::class);
    expect($team->organization->id)->toBe($org->id);
});

test('two teams cannot share a name within the same game', function () {
    $game = Game::factory()->create();
    $creator = Player::factory()->state(['game_id' => $game->id])->create();
    Team::factory()->create(['game_id' => $game->id, 'name' => 'Shared Name', 'created_by_player_id' => $creator->id]);

    expect(fn () => Team::factory()->create([
        'game_id'              => $game->id,
        'name'                 => 'Shared Name',
        'created_by_player_id' => Player::factory()->state(['game_id' => $game->id])->create()->id,
    ]))->toThrow(QueryException::class);
});

test('the same team name may be reused across different games', function () {
    $g1 = Game::factory()->create();
    $g2 = Game::factory()->create();
    Team::factory()->create([
        'game_id'              => $g1->id,
        'name'                 => 'CrossGame Squad',
        'created_by_player_id' => Player::factory()->state(['game_id' => $g1->id])->create()->id,
    ]);
    $second = Team::factory()->create([
        'game_id'              => $g2->id,
        'name'                 => 'CrossGame Squad',
        'created_by_player_id' => Player::factory()->state(['game_id' => $g2->id])->create()->id,
    ]);

    expect($second->id)->toBeGreaterThan(0);
});

test('soft-deleting a team keeps it recoverable', function () {
    $team = Team::factory()->create();
    $id   = $team->id;

    $team->delete();

    expect(Team::find($id))->toBeNull();
    expect(Team::withTrashed()->find($id)->trashed())->toBeTrue();
});

test('deleting an organisation does not delete its teams (SET NULL)', function () {
    $org  = Organization::factory()->create();
    $team = Team::factory()->forOrganization($org)->create();

    $org->forceDelete();
    $team->refresh();

    expect($team->organization_id)->toBeNull();
});

test('deleting a game with teams is RESTRICTed', function () {
    $game = Game::factory()->create();
    Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => Player::factory()->state(['game_id' => $game->id])->create()->id,
    ]);

    expect(fn () => $game->delete())->toThrow(QueryException::class);
});

test('hard-deleting the creator player is RESTRICTed while the team exists', function () {
    $team    = Team::factory()->create();
    $creator = $team->creator;

    expect(fn () => $creator->delete())->toThrow(QueryException::class);
});

test('isCreatedBy resolves true for the user who owns the creator player', function () {
    $user    = User::factory()->create();
    $game    = Game::factory()->create();
    $creator = Player::factory()->for($user)->for($game)->create();
    $team    = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => $creator->id,
    ]);

    expect($team->isCreatedBy($user))->toBeTrue();
    expect($team->isCreatedBy(User::factory()->create()))->toBeFalse();
});

test('isCaptainedBy resolves true for a user with an active captain seat', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create();

    // The user's player who's a captain
    $player = Player::factory()->for($user)->for($game)->create();
    $team   = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => Player::factory()->for($game)->create()->id,
    ]);
    TeamMember::factory()->captain()->create([
        'team_id'   => $team->id,
        'player_id' => $player->id,
    ]);

    expect($team->isCaptainedBy($user))->toBeTrue();
});

test('isCaptainedBy is false once the captain has left (left_at set)', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create();
    $player = Player::factory()->for($user)->for($game)->create();
    $team   = Team::factory()->create([
        'game_id'              => $game->id,
        'created_by_player_id' => Player::factory()->for($game)->create()->id,
    ]);
    TeamMember::factory()->captain()->left()->create([
        'team_id'   => $team->id,
        'player_id' => $player->id,
    ]);

    expect($team->isCaptainedBy($user))->toBeFalse();
});
