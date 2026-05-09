<?php

use App\Models\Game;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\TournamentHost;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\User;

use function Pest\Laravel\getJson;

// ---------------------------------------------------------------------------
// Tournament — game / host.user / organization
// ---------------------------------------------------------------------------

test('tournament index includes nested game, host (with user), and organization blocks', function () {
    $game  = Game::factory()->create(['name' => 'Rocket League', 'slug' => 'rocket-league']);
    $org   = Organization::factory()->create(['name' => 'MV Esports', 'slug' => 'mv-esports']);
    $hostUser = User::factory()->create(['display_name' => 'host-alice']);
    $host  = TournamentHost::factory()->create([
        'user_id'      => $hostUser->id,
        'display_name' => 'Host Alice',
    ]);
    Tournament::factory()->registrationOpen()->create([
        'game_id'         => $game->id,
        'organization_id' => $org->id,
        'host_id'         => $host->id,
    ]);

    $r = getJson('/api/tournaments')->assertOk();
    $first = $r->json('data.0');

    expect($first)->toHaveKeys(['game', 'host', 'organization']);
    expect($first['game']['name'])->toBe('Rocket League');
    expect($first['host']['display_name'])->toBe('Host Alice');
    expect($first['host']['user']['display_name'])->toBe('host-alice');
    expect($first['organization']['name'])->toBe('MV Esports');
});

test('tournament show includes the same nested blocks', function () {
    $tournament = Tournament::factory()->registrationOpen()->create();

    $r = getJson("/api/tournaments/{$tournament->id}")->assertOk();
    expect($r->json('data'))->toHaveKeys(['game', 'host', 'organization']);
});

// ---------------------------------------------------------------------------
// Match — participantA / participantB / winner
// ---------------------------------------------------------------------------

test('match index includes nested participant blocks (team type)', function () {
    $match = TournamentMatch::factory()->create();
    // Default factory creates Team participants.
    $r = getJson("/api/tournaments/{$match->stage->tournament_id}/stages/{$match->stage_id}/matches")
        ->assertOk();
    $first = $r->json('data.0');

    expect($first['participant_a']['type'])->toBe('team');
    expect($first['participant_a'])->toHaveKeys(['id', 'name', 'tag']);
    expect($first['participant_b']['type'])->toBe('team');
});

test('match show includes nested participant blocks', function () {
    $match = TournamentMatch::factory()->create();

    $r = getJson("/api/matches/{$match->id}")->assertOk();
    expect($r->json('data.participant_a.type'))->toBe('team');
});

// ---------------------------------------------------------------------------
// Player — user + game
// ---------------------------------------------------------------------------

test('player index includes nested user and game', function () {
    $game = Game::factory()->create(['name' => 'Rocket League']);
    $user = User::factory()->create(['display_name' => 'pro_player']);
    Player::factory()->create([
        'user_id'  => $user->id,
        'game_id'  => $game->id,
        'gamertag' => 'pro_tag',
    ]);

    $r = getJson('/api/players')->assertOk();
    $first = $r->json('data.0');

    expect($first['user']['display_name'])->toBe('pro_player');
    expect($first['game']['name'])->toBe('Rocket League');
});

// ---------------------------------------------------------------------------
// Team show — members.player.user
// ---------------------------------------------------------------------------

test('team show includes members with nested player and user', function () {
    $team = Team::factory()->create();
    $game = Game::find($team->game_id);
    $user = User::factory()->create(['display_name' => 'team_member']);
    $player = Player::factory()->create(['user_id' => $user->id, 'game_id' => $game->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'player_id' => $player->id]);

    $r = getJson("/api/teams/{$team->id}")->assertOk();

    expect($r->json('data.members'))->toBeArray();
    $member = collect($r->json('data.members'))->firstWhere('player_id', $player->id);
    expect($member)->not->toBeNull();
    expect($member['player']['gamertag'])->toBe($player->gamertag);
    expect($member['player']['user']['display_name'])->toBe('team_member');
});

// ---------------------------------------------------------------------------
// Organization show — owner
// ---------------------------------------------------------------------------

test('organization show includes nested owner', function () {
    $owner = User::factory()->create(['display_name' => 'org_owner']);
    $org   = Organization::factory()->create(['owner_user_id' => $owner->id]);

    $r = getJson("/api/organizations/{$org->id}")->assertOk();

    expect($r->json('data.owner.display_name'))->toBe('org_owner');
});

// ---------------------------------------------------------------------------
// Tournament registration — participant morph
// ---------------------------------------------------------------------------

test('tournament registration index includes nested participant block', function () {
    $tournament = Tournament::factory()->registrationOpen()->create();
    $team = Team::factory()->create(['game_id' => $tournament->game_id]);
    TournamentRegistration::factory()->create([
        'tournament_id'    => $tournament->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]);

    $r = getJson("/api/tournaments/{$tournament->id}/registrations")->assertOk();
    $first = $r->json('data.0');

    expect($first['participant']['type'])->toBe('team');
    expect($first['participant']['id'])->toBe($team->id);
});
