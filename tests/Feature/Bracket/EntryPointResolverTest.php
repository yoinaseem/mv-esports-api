<?php

use App\Enums\RegistrationStatus;
use App\Enums\StageParticipantStatus;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Services\Bracket\EntryPointResolver;

test('copies all approved registrations into the entry stage with seeds preserved', function () {
    $tournament = Tournament::factory()->create();
    $stage      = Stage::factory()->for($tournament)->create();

    for ($i = 1; $i <= 4; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        TournamentRegistration::factory()->approved()->create([
            'tournament_id'    => $tournament->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
        ]);
    }

    $created = (new EntryPointResolver())->resolve($tournament, $stage);

    expect($created)->toBe(4);
    expect($stage->participants()->count())->toBe(4);
    foreach ($stage->participants()->orderBy('seed')->get() as $i => $sp) {
        expect($sp->seed)->toBe($i + 1);
        expect($sp->status)->toBe(StageParticipantStatus::Active);
    }
});

test('does not copy pending or rejected registrations', function () {
    $tournament = Tournament::factory()->create();
    $stage      = Stage::factory()->for($tournament)->create();

    $approvedTeam = Team::factory()->create(['game_id' => $tournament->game_id]);
    TournamentRegistration::factory()->approved()->create([
        'tournament_id'    => $tournament->id,
        'participant_id'   => $approvedTeam->id,
        'seed'             => 1,
    ]);

    $pendingTeam = Team::factory()->create(['game_id' => $tournament->game_id]);
    TournamentRegistration::factory()->create([
        'tournament_id'    => $tournament->id,
        'participant_id'   => $pendingTeam->id,
        'status'           => RegistrationStatus::Pending,
        'seed'             => 2,
    ]);

    $rejectedTeam = Team::factory()->create(['game_id' => $tournament->game_id]);
    TournamentRegistration::factory()->rejected()->create([
        'tournament_id'    => $tournament->id,
        'participant_id'   => $rejectedTeam->id,
        'seed'             => 3,
    ]);

    (new EntryPointResolver())->resolve($tournament, $stage);

    expect($stage->participants()->count())->toBe(1);
    expect($stage->participants()->first()->participant_id)->toBe($approvedTeam->id);
});

test('returns 0 and skips when the stage already has participants', function () {
    $tournament = Tournament::factory()->create();
    $stage      = Stage::factory()->for($tournament)->create();
    $team       = Team::factory()->create(['game_id' => $tournament->game_id]);
    \App\Models\StageParticipant::factory()->create([
        'stage_id'       => $stage->id,
        'participant_id' => $team->id,
        'seed'           => 1,
    ]);

    // Even with approved registrations, the resolver shouldn't double-fill.
    $other = Team::factory()->create(['game_id' => $tournament->game_id]);
    TournamentRegistration::factory()->approved()->create([
        'tournament_id'  => $tournament->id,
        'participant_id' => $other->id,
        'seed'           => 2,
    ]);

    $created = (new EntryPointResolver())->resolve($tournament, $stage);

    expect($created)->toBe(0);
    expect($stage->participants()->count())->toBe(1);
});

test('null-seed registrations get auto-assigned sequential seeds (no NOT NULL violation)', function () {
    $tournament = Tournament::factory()->create();
    $stage      = Stage::factory()->for($tournament)->create();

    // Three approved registrations, none with explicit seeds — host forgot
    // to assign them. Resolver should auto-fill 1, 2, 3 by registered_at.
    $earliest = null;
    foreach ([10, 20, 30] as $offsetMinutes) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        $reg  = TournamentRegistration::factory()->approved()->create([
            'tournament_id'    => $tournament->id,
            'participant_id'   => $team->id,
            'seed'             => null,
            'registered_at'    => now()->subMinutes($offsetMinutes),
        ]);
        $earliest ??= $reg;
    }

    (new EntryPointResolver())->resolve($tournament, $stage);

    expect($stage->participants()->count())->toBe(3);
    $seeds = $stage->participants()->orderBy('seed')->pluck('seed')->toArray();
    expect($seeds)->toBe([1, 2, 3]); // sequential, no nulls
});

test('mixed seeded + unseeded registrations: explicit seeds win the order, unseeded fall back to registered_at', function () {
    $tournament = Tournament::factory()->create();
    $stage      = Stage::factory()->for($tournament)->create();

    // Build: reg with seed=2, reg with seed=1, reg with no seed (registered earliest).
    $teams = collect(range(1, 3))->map(fn () =>
        Team::factory()->create(['game_id' => $tournament->game_id])
    );

    $regSeed2 = TournamentRegistration::factory()->approved()->create([
        'tournament_id'    => $tournament->id,
        'participant_id'   => $teams[0]->id,
        'seed'             => 2,
        'registered_at'    => now()->subMinutes(5),
    ]);
    $regSeed1 = TournamentRegistration::factory()->approved()->create([
        'tournament_id'    => $tournament->id,
        'participant_id'   => $teams[1]->id,
        'seed'             => 1,
        'registered_at'    => now()->subMinutes(10),
    ]);
    $regNoSeed = TournamentRegistration::factory()->approved()->create([
        'tournament_id'    => $tournament->id,
        'participant_id'   => $teams[2]->id,
        'seed'             => null,
        'registered_at'    => now()->subMinutes(20),
    ]);

    (new EntryPointResolver())->resolve($tournament, $stage);

    // Final seeds 1..3, in order: regSeed1 → 1, regSeed2 → 2, regNoSeed → 3.
    $byTeam = $stage->participants()->get()->keyBy('participant_id');
    expect($byTeam[$teams[1]->id]->seed)->toBe(1); // explicit seed=1 wins
    expect($byTeam[$teams[0]->id]->seed)->toBe(2); // explicit seed=2 next
    expect($byTeam[$teams[2]->id]->seed)->toBe(3); // unseeded falls to last
});

test('orders inserts by seed ascending so DB ordering is deterministic', function () {
    $tournament = Tournament::factory()->create();
    $stage      = Stage::factory()->for($tournament)->create();

    // Create out of order; resolver should still order by seed.
    foreach ([3, 1, 4, 2] as $seed) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        TournamentRegistration::factory()->approved()->create([
            'tournament_id'    => $tournament->id,
            'participant_id'   => $team->id,
            'seed'             => $seed,
        ]);
    }

    (new EntryPointResolver())->resolve($tournament, $stage);

    $orderedSeeds = $stage->participants()->orderBy('id')->pluck('seed')->toArray();
    expect($orderedSeeds)->toBe([1, 2, 3, 4]);
});
