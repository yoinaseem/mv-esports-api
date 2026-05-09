<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/**
 * Each test asserts that the index endpoint executes a bounded number
 * of queries regardless of result count. Catches N+1 regressions where
 * a new resource accessor triggers per-row lazy-loading.
 *
 * Bounds are generous (2-3× theoretical minimum) — the goal is to
 * detect *order-of-magnitude* regressions, not micro-optimisations.
 */

test('tournament index does not N+1 across many tournaments', function () {
    Tournament::factory()->registrationOpen()->count(15)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    getJson('/api/tournaments?per_page=15')->assertOk();
    $count = count(DB::getQueryLog());

    // base + game + host + host.user + organization + count(*) for paginator
    // = ~6. Any value > 10 indicates an N+1 regression.
    expect($count)->toBeLessThanOrEqual(10);
});

test('match index does not N+1 across many matches with polymorphic participants', function () {
    $stage = \App\Models\Stage::factory()->create();
    TournamentMatch::factory()->count(15)->create(['stage_id' => $stage->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    getJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}/matches?per_page=20")->assertOk();
    $count = count(DB::getQueryLog());

    // base + participantA morph + participantB morph + winner morph + count
    // morphWith handles the Player → user nesting in fewer queries.
    expect($count)->toBeLessThanOrEqual(15);
});

test('tournament registrations index does not N+1', function () {
    $tournament = Tournament::factory()->registrationOpen()->create();
    for ($i = 0; $i < 10; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        TournamentRegistration::factory()->create([
            'tournament_id'    => $tournament->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    getJson("/api/tournaments/{$tournament->id}/registrations?per_page=10")->assertOk();
    $count = count(DB::getQueryLog());

    expect($count)->toBeLessThanOrEqual(10);
});

test('player index does not N+1 across many players', function () {
    $game = Game::factory()->create();
    for ($i = 0; $i < 10; $i++) {
        $user = User::factory()->create();
        Player::factory()->create(['user_id' => $user->id, 'game_id' => $game->id]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    getJson('/api/players?per_page=10')->assertOk();
    $count = count(DB::getQueryLog());

    // base + users + games + count = 4
    expect($count)->toBeLessThanOrEqual(8);
});

test('stage participant index does not N+1', function () {
    $stage = \App\Models\Stage::factory()->create();
    for ($i = 0; $i < 8; $i++) {
        $team = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
        \App\Models\StageParticipant::factory()->create([
            'stage_id'         => $stage->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    getJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}/participants?per_page=20")->assertOk();
    $count = count(DB::getQueryLog());

    // base + participant morph + count = ~4
    expect($count)->toBeLessThanOrEqual(8);
});
