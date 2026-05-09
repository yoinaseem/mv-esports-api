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
