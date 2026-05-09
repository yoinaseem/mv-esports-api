<?php

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Services\Bracket\RoundRobinGenerator;

function seedRoundRobin(Stage $stage, int $participants): void
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

test('4-team single group produces 6 matches across 3 rounds', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create();
    seedRoundRobin($stage, 4);

    $summary = (new RoundRobinGenerator())->generate($stage);
    expect($summary['matches_generated'])->toBe(6);

    $rounds = \App\Models\TournamentMatch::where('stage_id', $stage->id)
        ->distinct()
        ->pluck('bracket_round')
        ->sort()
        ->values();
    expect($rounds->toArray())->toBe([1, 2, 3]);
});

test('every pair plays exactly once in a single-group round-robin', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create();
    seedRoundRobin($stage, 4);
    (new RoundRobinGenerator())->generate($stage);

    $pairsSeen = collect();
    foreach ($stage->matches as $m) {
        $key = collect([$m->participant_a_id, $m->participant_b_id])->sort()->values()->implode('-');
        $pairsSeen->push($key);
    }
    expect($pairsSeen->unique()->count())->toBe(6); // C(4,2) = 6
    expect($pairsSeen->count())->toBe(6);
});

test('matches are bracket_type=group with group_number set', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create();
    seedRoundRobin($stage, 4);
    (new RoundRobinGenerator())->generate($stage);

    foreach ($stage->matches as $m) {
        expect($m->bracket_type)->toBe(BracketType::Group);
        expect($m->group_number)->toBe(1);
        expect($m->status)->toBe(MatchStatus::Scheduled);
    }
});

test('odd group size produces (n-1) × ceil(n/2) matches and assigns one bye round per participant', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 5)->create();
    seedRoundRobin($stage, 5);
    (new RoundRobinGenerator())->generate($stage);

    // 5 participants → C(5,2) = 10 actual games. With phantom: 5 rounds × 2 real matches = 10.
    expect($stage->matches()->count())->toBe(10);
    $rounds = \App\Models\TournamentMatch::where('stage_id', $stage->id)
        ->distinct()
        ->pluck('bracket_round');
    expect($rounds->count())->toBe(5);
});

test('multi-group round-robin snake-distributes seeds and produces independent group schedules', function () {
    $stage = Stage::factory()->roundRobin(groups: 2, groupSize: 4)->create();
    seedRoundRobin($stage, 8);
    (new RoundRobinGenerator())->generate($stage);

    // Each group has C(4,2) = 6 matches → total 12.
    expect($stage->matches()->count())->toBe(12);
    expect($stage->matches()->where('group_number', 1)->count())->toBe(6);
    expect($stage->matches()->where('group_number', 2)->count())->toBe(6);

    // Snake distribution: group 1 = seeds [1, 4, 5, 8]; group 2 = [2, 3, 6, 7].
    $bySeed = $stage->participants()->orderBy('seed')->get()->keyBy('seed');
    $g1Ids  = collect([1, 4, 5, 8])->map(fn ($s) => $bySeed[$s]->participant_id)->toArray();
    $g2Ids  = collect([2, 3, 6, 7])->map(fn ($s) => $bySeed[$s]->participant_id)->toArray();

    foreach ($stage->matches()->where('group_number', 1)->get() as $m) {
        expect($g1Ids)->toContain($m->participant_a_id);
        expect($g1Ids)->toContain($m->participant_b_id);
    }
    foreach ($stage->matches()->where('group_number', 2)->get() as $m) {
        expect($g2Ids)->toContain($m->participant_a_id);
        expect($g2Ids)->toContain($m->participant_b_id);
    }
});

test('group/group_size mismatch with participant count is rejected', function () {
    $stage = Stage::factory()->roundRobin(groups: 2, groupSize: 4)->create();
    seedRoundRobin($stage, 7); // 2 × 4 ≠ 7

    expect(fn () => (new RoundRobinGenerator())->generate($stage))
        ->toThrow(\DomainException::class);
});

test('round-robin matches have no advancement FKs', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create();
    seedRoundRobin($stage, 4);
    (new RoundRobinGenerator())->generate($stage);

    foreach ($stage->matches as $m) {
        expect($m->winner_advances_to_match_id)->toBeNull();
        expect($m->loser_advances_to_match_id)->toBeNull();
    }
});

test('rejects stages with fewer than 2 participants', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 1)->create();
    seedRoundRobin($stage, 1);

    expect(fn () => (new RoundRobinGenerator())->generate($stage))
        ->toThrow(\DomainException::class);
});
