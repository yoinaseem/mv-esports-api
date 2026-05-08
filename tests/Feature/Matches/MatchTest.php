<?php

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\Team;
use App\Models\TournamentMatch;

test('a match casts status and bracket_type as enums', function () {
    $match = TournamentMatch::factory()->create();

    expect($match->status)->toBeInstanceOf(MatchStatus::class);
    expect($match->bracket_type)->toBeInstanceOf(BracketType::class);
});

test('a match belongs to a stage and resolves polymorphic participants', function () {
    $match = TournamentMatch::factory()->create();

    expect($match->stage)->toBeInstanceOf(Stage::class);
    expect($match->participantA)->toBeInstanceOf(Team::class);
    expect($match->participantB)->toBeInstanceOf(Team::class);
});

test('participant types use morph aliases not FQCN', function () {
    $match = TournamentMatch::factory()->create();

    expect($match->participant_a_type)->toBe('team');
    expect($match->participant_b_type)->toBe('team');
});

test('advancement self-FK relationships resolve', function () {
    $finals    = TournamentMatch::factory()->create();
    $semifinal = TournamentMatch::factory()->create([
        'winner_advances_to_match_id' => $finals->id,
        'winner_advances_to_slot'     => 'a',
        'loser_advances_to_match_id'  => null,
    ]);

    expect($semifinal->winnerAdvancesTo)->toBeInstanceOf(TournamentMatch::class);
    expect($semifinal->winnerAdvancesTo->id)->toBe($finals->id);
});

test('deleting a stage cascades to its matches', function () {
    $stage = Stage::factory()->create();
    TournamentMatch::factory()->count(3)->state(['stage_id' => $stage->id])->create();

    $stage->forceDelete();

    expect(TournamentMatch::where('stage_id', $stage->id)->count())->toBe(0);
});

test('deleting a downstream match nulls the upstream advancement FK rather than cascading', function () {
    $finals    = TournamentMatch::factory()->create();
    $semifinal = TournamentMatch::factory()->create([
        'winner_advances_to_match_id' => $finals->id,
        'winner_advances_to_slot'     => 'a',
    ]);

    $finals->delete();
    $semifinal->refresh();

    expect($semifinal->winner_advances_to_match_id)->toBeNull();
    expect(TournamentMatch::find($semifinal->id))->not->toBeNull();
});

test('Stage::matches() returns matches ordered by round then position', function () {
    $stage = Stage::factory()->create();
    TournamentMatch::factory()->state(['stage_id' => $stage->id, 'bracket_round' => 2, 'bracket_position' => 0])->create();
    TournamentMatch::factory()->state(['stage_id' => $stage->id, 'bracket_round' => 1, 'bracket_position' => 1])->create();
    TournamentMatch::factory()->state(['stage_id' => $stage->id, 'bracket_round' => 1, 'bracket_position' => 0])->create();

    $matches = $stage->matches()->get();
    expect($matches[0]->bracket_round)->toBe(1);
    expect($matches[0]->bracket_position)->toBe(0);
    expect($matches[2]->bracket_round)->toBe(2);
});

test('factory states set the corresponding match status', function () {
    expect(TournamentMatch::factory()->pending()->create()->status)->toBe(MatchStatus::Pending);
    expect(TournamentMatch::factory()->inProgress()->create()->status)->toBe(MatchStatus::InProgress);
    expect(TournamentMatch::factory()->completed()->create()->status)->toBe(MatchStatus::Completed);
    expect(TournamentMatch::factory()->walkover()->create()->status)->toBe(MatchStatus::Walkover);
    expect(TournamentMatch::factory()->conditional()->create()->status)->toBe(MatchStatus::Conditional);
});
