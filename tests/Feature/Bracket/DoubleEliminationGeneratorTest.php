<?php

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Services\Bracket\DoubleEliminationGenerator;

function seedDoubleElim(Stage $stage, int $participants): void
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

test('4-team double-elim with reset produces 7 matches', function () {
    $stage = Stage::factory()->doubleElim()->create(); // grand_final_reset = true
    seedDoubleElim($stage, 4);

    $summary = (new DoubleEliminationGenerator())->generate($stage);

    // W: 3, L: 2, GF: 1, reset: 1 = 7
    expect($summary['matches_generated'])->toBe(7);
    expect($stage->matches()->count())->toBe(7);
});

test('4-team double-elim without reset produces 6 matches', function () {
    $stage = Stage::factory()->doubleElim()->create([
        'config' => ['grand_final_reset' => false],
    ]);
    seedDoubleElim($stage, 4);

    $summary = (new DoubleEliminationGenerator())->generate($stage);

    expect($summary['matches_generated'])->toBe(6);
});

test('8-team double-elim with reset produces 15 matches', function () {
    $stage = Stage::factory()->doubleElim()->create();
    seedDoubleElim($stage, 8);

    $summary = (new DoubleEliminationGenerator())->generate($stage);

    // W: 7, L: 6, GF: 1, reset: 1 = 15
    expect($summary['matches_generated'])->toBe(15);
});

test('grand final reset match is created in Conditional status', function () {
    $stage = Stage::factory()->doubleElim()->create();
    seedDoubleElim($stage, 4);
    (new DoubleEliminationGenerator())->generate($stage);

    $resetMatches = $stage->matches()
        ->where('bracket_type', BracketType::GrandFinal)
        ->where('status', MatchStatus::Conditional)
        ->get();

    expect($resetMatches->count())->toBe(1);
});

test('losers bracket is wired with the standard 8-team cross-drop pattern', function () {
    $stage = Stage::factory()->doubleElim()->create();
    seedDoubleElim($stage, 8);
    (new DoubleEliminationGenerator())->generate($stage);

    $w1 = $stage->matches()
        ->where('bracket_type', BracketType::Winners)
        ->where('bracket_round', 1)
        ->orderBy('bracket_position')
        ->get();
    $l1 = $stage->matches()
        ->where('bracket_type', BracketType::Losers)
        ->where('bracket_round', 1)
        ->orderBy('bracket_position')
        ->get();
    $l2 = $stage->matches()
        ->where('bracket_type', BracketType::Losers)
        ->where('bracket_round', 2)
        ->orderBy('bracket_position')
        ->get();
    $w2 = $stage->matches()
        ->where('bracket_type', BracketType::Winners)
        ->where('bracket_round', 2)
        ->orderBy('bracket_position')
        ->get();

    // W1 losers feed L1 in straight order.
    expect($w1[0]->loser_advances_to_match_id)->toBe($l1[0]->id);
    expect($w1[0]->loser_advances_to_slot)->toBe('a');
    expect($w1[1]->loser_advances_to_match_id)->toBe($l1[0]->id);
    expect($w1[1]->loser_advances_to_slot)->toBe('b');
    expect($w1[2]->loser_advances_to_match_id)->toBe($l1[1]->id);
    expect($w1[3]->loser_advances_to_match_id)->toBe($l1[1]->id);

    // W2 losers cross-drop into L2 (W2.0 → L2.1, W2.1 → L2.0).
    expect($w2[0]->loser_advances_to_match_id)->toBe($l2[1]->id);
    expect($w2[1]->loser_advances_to_match_id)->toBe($l2[0]->id);
});

test('winners-final winner goes to grand final slot a', function () {
    $stage = Stage::factory()->doubleElim()->create();
    seedDoubleElim($stage, 8);
    (new DoubleEliminationGenerator())->generate($stage);

    $wFinal = $stage->matches()
        ->where('bracket_type', BracketType::Winners)
        ->where('bracket_round', 3)
        ->first();
    $gf = $stage->matches()
        ->where('bracket_type', BracketType::GrandFinal)
        ->where('bracket_round', 1)
        ->first();

    expect($wFinal->winner_advances_to_match_id)->toBe($gf->id);
    expect($wFinal->winner_advances_to_slot)->toBe('a');
});

test('losers-final winner goes to grand final slot b', function () {
    $stage = Stage::factory()->doubleElim()->create();
    seedDoubleElim($stage, 8);
    (new DoubleEliminationGenerator())->generate($stage);

    // Bypass the matches() relation's orderBy clauses so orderByDesc actually
    // wins for picking the highest L round.
    $lFinal = \App\Models\TournamentMatch::where('stage_id', $stage->id)
        ->where('bracket_type', BracketType::Losers)
        ->orderByDesc('bracket_round')
        ->first();
    $gf = \App\Models\TournamentMatch::where('stage_id', $stage->id)
        ->where('bracket_type', BracketType::GrandFinal)
        ->where('bracket_round', 1)
        ->first();

    expect($lFinal->winner_advances_to_match_id)->toBe($gf->id);
    expect($lFinal->winner_advances_to_slot)->toBe('b');
});

test('non-power-of-two participant counts are rejected', function () {
    $stage = Stage::factory()->doubleElim()->create();
    seedDoubleElim($stage, 6);

    expect(fn () => (new DoubleEliminationGenerator())->generate($stage))
        ->toThrow(\DomainException::class);
});

test('participant counts other than 4/8/16/32 are rejected', function () {
    $stage = Stage::factory()->doubleElim()->create();
    seedDoubleElim($stage, 64);

    expect(fn () => (new DoubleEliminationGenerator())->generate($stage))
        ->toThrow(\DomainException::class);
});

test('round 1 winners-bracket matches start as Scheduled', function () {
    $stage = Stage::factory()->doubleElim()->create();
    seedDoubleElim($stage, 8);
    (new DoubleEliminationGenerator())->generate($stage);

    $r1 = $stage->matches()
        ->where('bracket_type', BracketType::Winners)
        ->where('bracket_round', 1)
        ->get();

    foreach ($r1 as $m) {
        expect($m->status)->toBe(MatchStatus::Scheduled);
    }
});

test('all losers-bracket matches start as Pending', function () {
    $stage = Stage::factory()->doubleElim()->create();
    seedDoubleElim($stage, 8);
    (new DoubleEliminationGenerator())->generate($stage);

    foreach ($stage->matches()->where('bracket_type', BracketType::Losers)->get() as $m) {
        expect($m->status)->toBe(MatchStatus::Pending);
    }
});
