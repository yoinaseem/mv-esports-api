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

test('SE: best_of_per_round applies per round; defaults to 1 for unspecified rounds', function () {
    $stage = Stage::factory()->create([
        'config' => ['best_of_per_round' => [1 => 3, 2 => 5, 3 => 7]],
    ]);
    seedStage($stage, 8);

    (new SingleEliminationGenerator())->generate($stage);

    $byRound = $stage->matches()->get()->groupBy('bracket_round');
    expect($byRound[1]->every(fn ($m) => $m->best_of === 3))->toBeTrue();
    expect($byRound[2]->every(fn ($m) => $m->best_of === 5))->toBeTrue();
    expect($byRound[3]->every(fn ($m) => $m->best_of === 7))->toBeTrue();
});

test('SE: third-place match inherits the final round bo', function () {
    $stage = Stage::factory()->create([
        'config' => [
            'third_place_match'  => true,
            'best_of_per_round'  => [2 => 5],
        ],
    ]);
    seedStage($stage, 4);

    (new SingleEliminationGenerator())->generate($stage);

    $finalRoundMatches = $stage->matches()->where('bracket_round', 2)->get();
    expect($finalRoundMatches)->toHaveCount(2); // final + third-place
    expect($finalRoundMatches->every(fn ($m) => $m->best_of === 5))->toBeTrue();
});

test('SE: missing config defaults all matches to bo1', function () {
    $stage = Stage::factory()->create(['config' => null]);
    seedStage($stage, 4);

    (new SingleEliminationGenerator())->generate($stage);

    expect($stage->matches()->get()->every(fn ($m) => $m->best_of === 1))->toBeTrue();
});

test('SE: partial map fills only specified rounds, others bo1', function () {
    $stage = Stage::factory()->create([
        'config' => ['best_of_per_round' => [3 => 7]], // only the final
    ]);
    seedStage($stage, 8);

    (new SingleEliminationGenerator())->generate($stage);

    $byRound = $stage->matches()->get()->groupBy('bracket_round');
    expect($byRound[1]->every(fn ($m) => $m->best_of === 1))->toBeTrue();
    expect($byRound[2]->every(fn ($m) => $m->best_of === 1))->toBeTrue();
    expect($byRound[3]->every(fn ($m) => $m->best_of === 7))->toBeTrue();
});

test('SE: string keys (JSON-decoded shape) work', function () {
    // When the map round-trips through JSON, integer keys become strings.
    $stage = Stage::factory()->create([
        'config' => ['best_of_per_round' => ['1' => 3, '2' => 5]],
    ]);
    seedStage($stage, 4);

    (new SingleEliminationGenerator())->generate($stage);

    $byRound = $stage->matches()->get()->groupBy('bracket_round');
    expect($byRound[1]->every(fn ($m) => $m->best_of === 3))->toBeTrue();
    expect($byRound[2]->every(fn ($m) => $m->best_of === 5))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Double elim
// ---------------------------------------------------------------------------

test('DE: best_of_per_round applies to W and L bracket; GF stays bo1', function () {
    $stage = Stage::factory()->doubleElim()->create([
        'config' => [
            'grand_final_reset'  => true,
            'best_of_per_round'  => [1 => 3, 2 => 5, 3 => 7],
        ],
    ]);
    seedStage($stage, 4);

    (new DoubleEliminationGenerator())->generate($stage);

    $w = $stage->matches()->where('bracket_type', BracketType::Winners)->get();
    $l = $stage->matches()->where('bracket_type', BracketType::Losers)->get();
    $gf = $stage->matches()->where('bracket_type', BracketType::GrandFinal)->get();

    // 4-team DE: W rounds 1 (2 matches) + 2 (1 match). L rounds 1 + 2 (1 match each).
    expect($w->where('bracket_round', 1)->every(fn ($m) => $m->best_of === 3))->toBeTrue();
    expect($w->where('bracket_round', 2)->every(fn ($m) => $m->best_of === 5))->toBeTrue();
    expect($l->where('bracket_round', 1)->every(fn ($m) => $m->best_of === 3))->toBeTrue();
    expect($l->where('bracket_round', 2)->every(fn ($m) => $m->best_of === 5))->toBeTrue();
    // GF and reset stay at bo1 — host PATCHes them post-build for a bo7 final.
    expect($gf->every(fn ($m) => $m->best_of === 1))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Round robin
// ---------------------------------------------------------------------------

test('RR: best_of_per_round applies per circle-method round', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create();
    $stage->update(['config' => array_merge($stage->config ?? [], [
        'best_of_per_round' => [1 => 3, 2 => 5, 3 => 7],
    ])]);
    seedStage($stage, 4);

    (new RoundRobinGenerator())->generate($stage);

    $byRound = $stage->matches()->get()->groupBy('bracket_round');
    expect($byRound[1]->every(fn ($m) => $m->best_of === 3))->toBeTrue();
    expect($byRound[2]->every(fn ($m) => $m->best_of === 5))->toBeTrue();
    expect($byRound[3]->every(fn ($m) => $m->best_of === 7))->toBeTrue();
});

test('RR: same per-round mapping applies across multi-group RR', function () {
    $stage = Stage::factory()->roundRobin(groups: 2, groupSize: 4)->create();
    $stage->update(['config' => array_merge($stage->config ?? [], [
        'best_of_per_round' => [1 => 3, 2 => 3, 3 => 5],
    ])]);
    seedStage($stage, 8);

    (new RoundRobinGenerator())->generate($stage);

    foreach ([1, 2] as $groupNumber) {
        $g = $stage->matches()->where('group_number', $groupNumber)->get()->groupBy('bracket_round');
        expect($g[1]->every(fn ($m) => $m->best_of === 3))->toBeTrue();
        expect($g[2]->every(fn ($m) => $m->best_of === 3))->toBeTrue();
        expect($g[3]->every(fn ($m) => $m->best_of === 5))->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('validation rejects even best_of values', function () {
    $admin = \App\Models\User::factory()->systemManager()->create();
    $tournament = \App\Models\Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'single_elim',
            'config' => ['best_of_per_round' => [1 => 4]], // even — invalid
        ])
        ->assertStatus(422);
});

test('validation rejects negative round keys', function () {
    $admin = \App\Models\User::factory()->systemManager()->create();
    $tournament = \App\Models\Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'single_elim',
            'config' => ['best_of_per_round' => [-1 => 3]],
        ])
        ->assertStatus(422);
});

test('validation accepts a valid map', function () {
    $admin = \App\Models\User::factory()->systemManager()->create();
    $tournament = \App\Models\Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'single_elim',
            'config' => ['best_of_per_round' => [1 => 3, 2 => 5, 3 => 7]],
        ])
        ->assertStatus(201);
});
