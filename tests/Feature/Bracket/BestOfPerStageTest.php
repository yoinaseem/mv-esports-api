<?php

use App\Enums\BracketType;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Services\Bracket\DoubleEliminationGenerator;
use App\Services\Bracket\RoundRobinGenerator;
use App\Services\Bracket\SingleEliminationGenerator;

function seedStage(Stage $stage, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        $team = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id'         => $stage->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
        ]);
    }
}

// ---------------------------------------------------------------------------
// Single elim
// ---------------------------------------------------------------------------

test('SE: stage best_of applies to every match', function () {
    $stage = Stage::factory()->create([
        'config' => ['best_of' => 5],
    ]);
    seedStage($stage, 8);

    (new SingleEliminationGenerator())->generate($stage);

    expect($stage->matches()->get()->every(fn ($m) => $m->best_of === 5))->toBeTrue();
});

test('SE: third-place match inherits the stage best_of', function () {
    $stage = Stage::factory()->create([
        'config' => [
            'third_place_match' => true,
            'best_of'           => 3,
        ],
    ]);
    seedStage($stage, 4);

    (new SingleEliminationGenerator())->generate($stage);

    $finalRound = $stage->matches()->where('bracket_round', 2)->get();
    expect($finalRound)->toHaveCount(2); // final + third-place
    expect($finalRound->every(fn ($m) => $m->best_of === 3))->toBeTrue();
});

test('SE: missing config defaults every match to bo1', function () {
    $stage = Stage::factory()->create(['config' => null]);
    seedStage($stage, 4);

    (new SingleEliminationGenerator())->generate($stage);

    expect($stage->matches()->get()->every(fn ($m) => $m->best_of === 1))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Double elim
// ---------------------------------------------------------------------------

test('DE: stage best_of applies to W, L, GF, and reset', function () {
    $stage = Stage::factory()->doubleElim()->create([
        'config' => [
            'grand_final_reset' => true,
            'best_of'           => 5,
        ],
    ]);
    seedStage($stage, 4);

    (new DoubleEliminationGenerator())->generate($stage);

    expect($stage->matches()->get()->every(fn ($m) => $m->best_of === 5))->toBeTrue();
});

test('DE: missing config defaults every match to bo1 (including GF and reset)', function () {
    $stage = Stage::factory()->doubleElim()->create([
        'config' => ['grand_final_reset' => true],
    ]);
    seedStage($stage, 4);

    (new DoubleEliminationGenerator())->generate($stage);

    expect($stage->matches()->get()->every(fn ($m) => $m->best_of === 1))->toBeTrue();
});

test('DE: per-match PATCH still wins for the GF after build', function () {
    // The host wants a bo7 grand final on a stage that's otherwise bo3.
    // After build they PATCH the GF row directly — generator-time best_of
    // is just the default.
    $stage = Stage::factory()->doubleElim()->create([
        'config' => ['best_of' => 3],
    ]);
    seedStage($stage, 4);

    (new DoubleEliminationGenerator())->generate($stage);

    $gf = $stage->matches()->where('bracket_type', BracketType::GrandFinal)->first();
    $gf->update(['best_of' => 7]);

    expect($stage->matches()->where('bracket_type', BracketType::Winners)->get()->every(fn ($m) => $m->best_of === 3))->toBeTrue();
    expect($stage->matches()->where('bracket_type', BracketType::Losers)->get()->every(fn ($m) => $m->best_of === 3))->toBeTrue();
    expect($gf->fresh()->best_of)->toBe(7);
});

// ---------------------------------------------------------------------------
// Round robin
// ---------------------------------------------------------------------------

test('RR: stage best_of applies to every circle-method match', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create();
    $stage->update(['config' => array_merge($stage->config ?? [], ['best_of' => 3])]);
    seedStage($stage, 4);

    (new RoundRobinGenerator())->generate($stage);

    expect($stage->matches()->get()->every(fn ($m) => $m->best_of === 3))->toBeTrue();
});

test('RR: stage best_of applies uniformly across multi-group RR', function () {
    $stage = Stage::factory()->roundRobin(groups: 2, groupSize: 4)->create();
    $stage->update(['config' => array_merge($stage->config ?? [], ['best_of' => 5])]);
    seedStage($stage, 8);

    (new RoundRobinGenerator())->generate($stage);

    expect($stage->matches()->get()->every(fn ($m) => $m->best_of === 5))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('validation rejects an even best_of', function () {
    $admin = \App\Models\User::factory()->systemManager()->create();
    $tournament = \App\Models\Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'single_elim',
            'config' => ['best_of' => 4], // even — invalid
        ])
        ->assertStatus(422);
});

test('validation rejects an out-of-range best_of', function () {
    $admin = \App\Models\User::factory()->systemManager()->create();
    $tournament = \App\Models\Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'single_elim',
            'config' => ['best_of' => 101],
        ])
        ->assertStatus(422);
});

test('validation rejects a non-integer best_of', function () {
    $admin = \App\Models\User::factory()->systemManager()->create();
    $tournament = \App\Models\Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'single_elim',
            'config' => ['best_of' => 'three'],
        ])
        ->assertStatus(422);
});

test('validation accepts a valid odd best_of', function () {
    $admin = \App\Models\User::factory()->systemManager()->create();
    $tournament = \App\Models\Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'single_elim',
            'config' => ['best_of' => 7],
        ])
        ->assertStatus(201);
});
