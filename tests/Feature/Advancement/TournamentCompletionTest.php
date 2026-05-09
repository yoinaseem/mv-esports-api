<?php

use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\Tournament;
use App\Services\Advancement\TournamentCompletion;

test('does nothing if any stage is not yet Completed', function () {
    $tournament = Tournament::factory()->state(['status' => TournamentStatus::InProgress])->create();
    Stage::factory()->for($tournament)->create(['status' => StageStatus::Completed, 'sort_order' => 0]);
    Stage::factory()->for($tournament)->create(['status' => StageStatus::InProgress, 'sort_order' => 1]);

    app(TournamentCompletion::class)->checkAndClose($tournament);

    expect($tournament->fresh()->status)->toBe(TournamentStatus::InProgress);
});

test('transitions tournament to Completed when all stages are Completed', function () {
    $tournament = Tournament::factory()->state(['status' => TournamentStatus::InProgress])->create();
    Stage::factory()->for($tournament)->create(['status' => StageStatus::Completed, 'sort_order' => 0]);
    Stage::factory()->for($tournament)->create(['status' => StageStatus::Completed, 'sort_order' => 1]);

    app(TournamentCompletion::class)->checkAndClose($tournament);

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Completed);
});

test('does nothing when the tournament status cannot transition (already terminal)', function () {
    $tournament = Tournament::factory()->state(['status' => TournamentStatus::Completed])->create();
    Stage::factory()->for($tournament)->create(['status' => StageStatus::Completed]);

    app(TournamentCompletion::class)->checkAndClose($tournament);

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Completed); // unchanged
});
