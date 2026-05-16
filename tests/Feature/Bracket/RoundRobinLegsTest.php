<?php

use App\Enums\BracketType;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\Bracket\RoundRobinGenerator;

/**
 * Round-robin legs — `stage.config.legs` (int 1..10, default 1) controls
 * how many times each pair plays. Multi-leg replays the whole circle
 * schedule, offsetting `bracket_round` per leg so each leg occupies a
 * distinct round block. No participant a/b swap between legs.
 */

// ---------------------------------------------------------------------------
// Stage config validation
// ---------------------------------------------------------------------------

test('validation accepts legs=2 on round_robin', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Group Stage',
            'format' => 'round_robin',
            'config' => ['groups' => 1, 'group_size' => 4, 'legs' => 2],
        ])
        ->assertStatus(201);
});

test('validation rejects legs on single_elim', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'single_elim',
            'config' => ['legs' => 2],
        ])
        ->assertStatus(422);
});

test('validation rejects legs on double_elim', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'double_elim',
            'config' => ['legs' => 2],
        ])
        ->assertStatus(422);
});

test('validation rejects legs below 1', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Group Stage',
            'format' => 'round_robin',
            'config' => ['groups' => 1, 'group_size' => 4, 'legs' => 0],
        ])
        ->assertStatus(422);
});

test('validation rejects legs above 10', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Group Stage',
            'format' => 'round_robin',
            'config' => ['groups' => 1, 'group_size' => 4, 'legs' => 11],
        ])
        ->assertStatus(422);
});

test('validation rejects non-integer legs', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Group Stage',
            'format' => 'round_robin',
            'config' => ['groups' => 1, 'group_size' => 4, 'legs' => '2'],
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Generator behaviour
// ---------------------------------------------------------------------------

function seedRoundRobinLegs(Stage $stage, int $participants): void
{
    for ($i = 1; $i <= $participants; $i++) {
        $team = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id'         => $stage->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
        ]);
    }
}

test('legs=2 doubles the match count and uses contiguous bracket_round numbering', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create([
        'config' => ['groups' => 1, 'group_size' => 4, 'legs' => 2],
    ]);
    seedRoundRobinLegs($stage, 4);

    $summary = (new RoundRobinGenerator())->generate($stage);
    expect($summary['matches_generated'])->toBe(12); // 6 per leg × 2 legs

    $rounds = TournamentMatch::where('stage_id', $stage->id)
        ->distinct()
        ->pluck('bracket_round')
        ->sort()
        ->values();
    expect($rounds->toArray())->toBe([1, 2, 3, 4, 5, 6]);
});

test('legs=3 triples the match count', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create([
        'config' => ['groups' => 1, 'group_size' => 4, 'legs' => 3],
    ]);
    seedRoundRobinLegs($stage, 4);

    $summary = (new RoundRobinGenerator())->generate($stage);
    expect($summary['matches_generated'])->toBe(18); // 6 per leg × 3 legs

    $rounds = TournamentMatch::where('stage_id', $stage->id)
        ->distinct()
        ->pluck('bracket_round')
        ->sort()
        ->values();
    expect($rounds->toArray())->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9]);
});

test('each pair plays exactly `legs` times', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create([
        'config' => ['groups' => 1, 'group_size' => 4, 'legs' => 3],
    ]);
    seedRoundRobinLegs($stage, 4);
    (new RoundRobinGenerator())->generate($stage);

    $pairsSeen = collect();
    foreach ($stage->matches as $m) {
        $key = collect([$m->participant_a_id, $m->participant_b_id])->sort()->values()->implode('-');
        $pairsSeen->push($key);
    }
    // C(4, 2) = 6 unique pairs, each appearing 3 times → 18 rows.
    expect($pairsSeen->unique()->count())->toBe(6);
    expect($pairsSeen->count())->toBe(18);
    foreach ($pairsSeen->countBy() as $count) {
        expect($count)->toBe(3);
    }
});

test('participant a/b order is preserved across legs (no home/away swap)', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create([
        'config' => ['groups' => 1, 'group_size' => 4, 'legs' => 2],
    ]);
    seedRoundRobinLegs($stage, 4);
    (new RoundRobinGenerator())->generate($stage);

    // Index every leg-1 match by its unordered pair-key, then verify each
    // leg-2 match (same unordered pair) has the same a/b in the same order.
    $matches = $stage->matches()->orderBy('bracket_round')->orderBy('bracket_position')->get();
    $leg1 = $matches->filter(fn ($m) => $m->bracket_round <= 3);
    $leg2 = $matches->filter(fn ($m) => $m->bracket_round >= 4);

    $leg1ByPair = $leg1->keyBy(fn ($m) => min($m->participant_a_id, $m->participant_b_id)
        . '-' . max($m->participant_a_id, $m->participant_b_id));

    foreach ($leg2 as $m) {
        $key   = min($m->participant_a_id, $m->participant_b_id)
               . '-' . max($m->participant_a_id, $m->participant_b_id);
        $first = $leg1ByPair->get($key);
        expect($first)->not->toBeNull();
        expect($m->participant_a_id)->toBe($first->participant_a_id);
        expect($m->participant_b_id)->toBe($first->participant_b_id);
    }
});

test('multi-group + legs combine: 2 groups of 4 with legs=2 produces 24 matches', function () {
    $stage = Stage::factory()->roundRobin(groups: 2, groupSize: 4)->create([
        'config' => ['groups' => 2, 'group_size' => 4, 'legs' => 2],
    ]);
    seedRoundRobinLegs($stage, 8);
    (new RoundRobinGenerator())->generate($stage);

    expect($stage->matches()->count())->toBe(24); // 6 per group per leg × 2 groups × 2 legs
    expect($stage->matches()->where('group_number', 1)->count())->toBe(12);
    expect($stage->matches()->where('group_number', 2)->count())->toBe(12);
});

test('legs=1 (default omitted) matches the existing single-leg behaviour', function () {
    $stageA = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create();
    $stageB = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create([
        'config' => ['groups' => 1, 'group_size' => 4, 'legs' => 1],
    ]);
    seedRoundRobinLegs($stageA, 4);
    seedRoundRobinLegs($stageB, 4);

    (new RoundRobinGenerator())->generate($stageA);
    (new RoundRobinGenerator())->generate($stageB);

    expect($stageA->matches()->count())->toBe($stageB->matches()->count());
    expect($stageA->matches()->count())->toBe(6);
});

test('legs with odd group size still skips phantom bye rounds per leg', function () {
    // 5 participants → 5 rounds per leg, 2 real matches per round = 10 matches per leg.
    // legs=2 → 20 matches across 10 bracket_round values.
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 5)->create([
        'config' => ['groups' => 1, 'group_size' => 5, 'legs' => 2],
    ]);
    seedRoundRobinLegs($stage, 5);
    (new RoundRobinGenerator())->generate($stage);

    expect($stage->matches()->count())->toBe(20);
    $rounds = TournamentMatch::where('stage_id', $stage->id)
        ->distinct()
        ->pluck('bracket_round');
    expect($rounds->count())->toBe(10);
});

test('bracket_type stays group across all legs', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create([
        'config' => ['groups' => 1, 'group_size' => 4, 'legs' => 3],
    ]);
    seedRoundRobinLegs($stage, 4);
    (new RoundRobinGenerator())->generate($stage);

    foreach ($stage->matches as $m) {
        expect($m->bracket_type)->toBe(BracketType::Group);
        expect($m->group_number)->toBe(1);
    }
});
