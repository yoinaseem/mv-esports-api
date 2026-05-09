<?php

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Models\TournamentMatch;
use App\Services\Bracket\SingleEliminationGenerator;

function seedSingleElim(Stage $stage, int $participants): void
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

test('generates 7 matches for an 8-team bracket', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 8);

    $summary = (new SingleEliminationGenerator())->generate($stage);

    expect($summary['matches_generated'])->toBe(7);
    expect($summary['byes_assigned'])->toBe(0);
    expect($stage->matches()->count())->toBe(7);
});

test('round 1 matches in an 8-team bracket are all Scheduled with both slots filled', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 8);
    (new SingleEliminationGenerator())->generate($stage);

    $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();

    expect($r1->count())->toBe(4);
    foreach ($r1 as $m) {
        expect($m->status)->toBe(MatchStatus::Scheduled);
        expect($m->participant_a_id)->not->toBeNull();
        expect($m->participant_b_id)->not->toBeNull();
        expect($m->bracket_type)->toBe(BracketType::Winners);
    }
});

test('round 1 follows the canonical 1v8 / 4v5 / 2v7 / 3v6 seed-order', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 8);
    (new SingleEliminationGenerator())->generate($stage);

    // Look up seeds by stage_participant.participant_id keyed by seed number.
    $bySeed = $stage->participants()->orderBy('seed')->get()->keyBy('seed');
    $r1     = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();

    $expected = [[1, 8], [4, 5], [2, 7], [3, 6]];
    foreach ($expected as $i => [$sa, $sb]) {
        expect($r1[$i]->participant_a_id)->toBe($bySeed[$sa]->participant_id);
        expect($r1[$i]->participant_b_id)->toBe($bySeed[$sb]->participant_id);
    }
});

test('rounds 2 and 3 are Pending with empty slots', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 8);
    (new SingleEliminationGenerator())->generate($stage);

    $r2 = $stage->matches()->where('bracket_round', 2)->get();
    $r3 = $stage->matches()->where('bracket_round', 3)->get();

    expect($r2->count())->toBe(2);
    expect($r3->count())->toBe(1);
    foreach ($r2->concat($r3) as $m) {
        expect($m->status)->toBe(MatchStatus::Pending);
        expect($m->participant_a_id)->toBeNull();
        expect($m->participant_b_id)->toBeNull();
    }
});

test('advancement FKs wire round 1 → round 2 → round 3', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 8);
    (new SingleEliminationGenerator())->generate($stage);

    $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
    $r2 = $stage->matches()->where('bracket_round', 2)->orderBy('bracket_position')->get();
    $r3 = $stage->matches()->where('bracket_round', 3)->first();

    // r1.0 + r1.1 → r2.0 (slot a, b)
    expect($r1[0]->winner_advances_to_match_id)->toBe($r2[0]->id);
    expect($r1[0]->winner_advances_to_slot)->toBe('a');
    expect($r1[1]->winner_advances_to_match_id)->toBe($r2[0]->id);
    expect($r1[1]->winner_advances_to_slot)->toBe('b');
    // r1.2 + r1.3 → r2.1
    expect($r1[2]->winner_advances_to_match_id)->toBe($r2[1]->id);
    expect($r1[3]->winner_advances_to_slot)->toBe('b');
    // r2.0 + r2.1 → r3.0
    expect($r2[0]->winner_advances_to_match_id)->toBe($r3->id);
    expect($r2[1]->winner_advances_to_match_id)->toBe($r3->id);
    // r3 (final) advances nowhere
    expect($r3->winner_advances_to_match_id)->toBeNull();
});

test('losers in single-elim do not advance (loser FKs are null)', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 8);
    (new SingleEliminationGenerator())->generate($stage);

    foreach ($stage->matches as $m) {
        expect($m->loser_advances_to_match_id)->toBeNull();
    }
});

test('6-team bracket pads to size 8 and assigns 2 byes to the top seeds', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 6);
    $summary = (new SingleEliminationGenerator())->generate($stage);

    expect($summary['byes_assigned'])->toBe(2);

    $bySeed = $stage->participants()->orderBy('seed')->get()->keyBy('seed');
    // Per the seed-order [1, 8, 4, 5, 2, 7, 3, 6] with seeds 7 and 8 missing,
    // r1 pos 0 is "seed 1 vs (bye)" and r1 pos 2 is "seed 2 vs (bye)".
    $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();

    expect($r1[0]->status)->toBe(MatchStatus::Walkover);
    expect($r1[0]->participant_a_id)->toBe($bySeed[1]->participant_id);
    expect($r1[0]->participant_b_id)->toBeNull();
    expect($r1[0]->winner_participant_id)->toBe($bySeed[1]->participant_id);
    expect($r1[0]->completed_at)->not->toBeNull();

    expect($r1[2]->status)->toBe(MatchStatus::Walkover);
    expect($r1[2]->participant_a_id)->toBe($bySeed[2]->participant_id);
    expect($r1[2]->participant_b_id)->toBeNull();
    expect($r1[2]->winner_participant_id)->toBe($bySeed[2]->participant_id);

    // The other two r1 matches (positions 1 and 3) are real games.
    expect($r1[1]->status)->toBe(MatchStatus::Scheduled);
    expect($r1[3]->status)->toBe(MatchStatus::Scheduled);
});

test('5-team bracket pads to size 8 with 3 byes', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 5);
    $summary = (new SingleEliminationGenerator())->generate($stage);

    expect($summary['byes_assigned'])->toBe(3);
    expect($stage->matches()->where('status', MatchStatus::Walkover)->count())->toBe(3);
});

test('2-team bracket has a single match in round 1 and no round 2', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 2);
    $summary = (new SingleEliminationGenerator())->generate($stage);

    expect($summary['matches_generated'])->toBe(1);
    expect($stage->matches()->count())->toBe(1);
    expect($stage->matches()->first()->status)->toBe(MatchStatus::Scheduled);
});

test('third_place_match adds an extra match alongside the final', function () {
    $stage = Stage::factory()->create([
        'config' => ['third_place_match' => true],
    ]);
    seedSingleElim($stage, 4);
    $summary = (new SingleEliminationGenerator())->generate($stage);

    expect($summary['matches_generated'])->toBe(4); // 2 R1 + 1 final + 1 third-place
    $finalRound = $stage->matches()->where('bracket_round', 2)->orderBy('bracket_position')->get();
    expect($finalRound->count())->toBe(2);
    expect($finalRound[0]->bracket_position)->toBe(0); // final
    expect($finalRound[1]->bracket_position)->toBe(1); // third-place

    // Semifinal losers feed into the third-place match.
    $semis = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
    $tp    = $finalRound[1];
    expect($semis[0]->loser_advances_to_match_id)->toBe($tp->id);
    expect($semis[0]->loser_advances_to_slot)->toBe('a');
    expect($semis[1]->loser_advances_to_match_id)->toBe($tp->id);
    expect($semis[1]->loser_advances_to_slot)->toBe('b');
});

test('rejects stages with fewer than 2 participants', function () {
    $stage = Stage::factory()->create();
    seedSingleElim($stage, 1);

    expect(fn () => (new SingleEliminationGenerator())->generate($stage))
        ->toThrow(\DomainException::class);
});
