<?php

use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Services\Bracket\SeedAndBuildService;

function buildOrchestratorTournament(int $teams = 8, string $format = 'single_elim'): Tournament
{
    $tournament = Tournament::factory()->state([
        'status'           => TournamentStatus::RegistrationClosed,
        'participant_type' => 'team',
    ])->create();

    $stageState = ['format' => $format, 'sort_order' => 0];
    if ($format === 'round_robin') {
        $stageState['config'] = ['groups' => 1, 'group_size' => $teams];
    }
    if ($format === 'double_elim') {
        $stageState['config'] = ['grand_final_reset' => true];
    }
    $stage = Stage::factory()->for($tournament)->create($stageState);

    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);

    for ($i = 1; $i <= $teams; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        TournamentRegistration::factory()->approved()->create([
            'tournament_id'    => $tournament->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
        ]);
    }

    return $tournament->fresh();
}

test('happy path: builds an 8-team single-elim and transitions statuses', function () {
    $tournament = buildOrchestratorTournament(8, 'single_elim');

    $summary = app(SeedAndBuildService::class)->execute($tournament);

    expect($summary['tournament_id'])->toBe($tournament->id);
    expect($summary['stages'])->toHaveCount(1);
    expect($summary['stages'][0]['matches_generated'])->toBe(7);
    expect($summary['stages'][0]['format'])->toBe('single_elim');

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::InProgress);

    $stage = $tournament->stages()->first()->refresh();
    expect($stage->status)->toBe(StageStatus::InProgress);
    expect($stage->participants()->count())->toBe(8);
    expect($stage->matches()->count())->toBe(7);
});

test('builds round_robin', function () {
    $tournament = buildOrchestratorTournament(4, 'round_robin');

    $summary = app(SeedAndBuildService::class)->execute($tournament);

    expect($summary['stages'][0]['matches_generated'])->toBe(6);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::InProgress);
});

test('builds double_elim with the reset match in Conditional', function () {
    $tournament = buildOrchestratorTournament(4, 'double_elim');

    $summary = app(SeedAndBuildService::class)->execute($tournament);

    expect($summary['stages'][0]['matches_generated'])->toBe(7);
    $stage = $tournament->fresh()->stages()->first();
    expect($stage->matches()
        ->where('bracket_type', \App\Enums\BracketType::GrandFinal)
        ->where('status', \App\Enums\MatchStatus::Conditional)
        ->count())->toBe(1);
});

test('rejects when tournament is not in RegistrationClosed', function () {
    $tournament = buildOrchestratorTournament(8);
    $tournament->update(['status' => TournamentStatus::RegistrationOpen]);

    expect(fn () => app(SeedAndBuildService::class)->execute($tournament))
        ->toThrow(\DomainException::class);
});

test('rejects rebuild — any pre-existing match in any stage is fatal', function () {
    $tournament = buildOrchestratorTournament(8);
    $stage      = $tournament->stages()->first();

    // Manually add a match to simulate a prior partial run.
    StageParticipant::factory()->count(2)->create(['stage_id' => $stage->id]);
    TournamentMatch::factory()->create([
        'stage_id' => $stage->id,
        'bracket_round'    => 1,
        'bracket_position' => 0,
    ]);

    expect(fn () => app(SeedAndBuildService::class)->execute($tournament->fresh()))
        ->toThrow(\DomainException::class);
});

test('rejects null-source qualifications other than all or manual', function () {
    $tournament = Tournament::factory()->state([
        'status'           => TournamentStatus::RegistrationClosed,
        'participant_type' => 'team',
    ])->create();
    $stage = Stage::factory()->for($tournament)->create(['format' => 'single_elim']);
    StageQualification::factory()->fromRegistrations()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
        'rule_type'       => 'top_n_per_group',
        'rule_config'     => ['per_group' => 2, 'placement_strategy' => 'cross_group'],
    ]);

    expect(fn () => app(SeedAndBuildService::class)->execute($tournament))
        ->toThrow(\DomainException::class);
});

test('manual entry-point qualification skips the resolver but the build still proceeds', function () {
    $tournament = Tournament::factory()->state([
        'status'           => TournamentStatus::RegistrationClosed,
        'participant_type' => 'team',
    ])->create();
    $stage = Stage::factory()->for($tournament)->create(['format' => 'single_elim']);
    StageQualification::factory()->fromRegistrations()->manual()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);

    // Host pre-populates participants manually.
    for ($i = 1; $i <= 4; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id'         => $stage->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
        ]);
    }

    $summary = app(SeedAndBuildService::class)->execute($tournament);
    expect($summary['stages'][0]['matches_generated'])->toBe(3);
});

test('transaction rolls back if a generator throws — no partial state', function () {
    // Force a generator failure by giving the DE stage 6 participants
    // (non-power-of-2). The DE generator throws; transaction rolls back.
    $tournament = buildOrchestratorTournament(6, 'double_elim');

    expect(fn () => app(SeedAndBuildService::class)->execute($tournament))
        ->toThrow(\DomainException::class);

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::RegistrationClosed);
    expect($tournament->stages()->first()->status)->toBe(StageStatus::Pending);
    expect($tournament->stages()->first()->matches()->count())->toBe(0);
});

test('participant-type tripwire fires if a stage_participant disagrees with the tournament type', function () {
    $tournament = Tournament::factory()->state([
        'status'           => TournamentStatus::RegistrationClosed,
        'participant_type' => 'team',
    ])->create();
    $stage = Stage::factory()->for($tournament)->create(['format' => 'single_elim']);
    StageQualification::factory()->fromRegistrations()->manual()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);

    // Manually inject a player-typed participant into a team-typed tournament.
    $player = \App\Models\Player::factory()->create();
    StageParticipant::factory()->create([
        'stage_id'         => $stage->id,
        'participant_type' => 'player',  // disagrees with tournament.participant_type='team'
        'participant_id'   => $player->id,
        'seed'             => 1,
    ]);
    $team = Team::factory()->create(['game_id' => $tournament->game_id]);
    StageParticipant::factory()->create([
        'stage_id'         => $stage->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
        'seed'             => 2,
    ]);

    expect(fn () => app(SeedAndBuildService::class)->execute($tournament))
        ->toThrow(\DomainException::class);
});

test('rejects when the entry stage has fewer than 2 participants after resolution', function () {
    $tournament = Tournament::factory()->state([
        'status'           => TournamentStatus::RegistrationClosed,
        'participant_type' => 'team',
    ])->create();
    $stage = Stage::factory()->for($tournament)->create(['format' => 'single_elim']);
    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);

    // Only one approved registration.
    $team = Team::factory()->create(['game_id' => $tournament->game_id]);
    TournamentRegistration::factory()->approved()->create([
        'tournament_id'    => $tournament->id,
        'participant_id'   => $team->id,
        'seed'             => 1,
    ]);

    expect(fn () => app(SeedAndBuildService::class)->execute($tournament))
        ->toThrow(\DomainException::class);
});
