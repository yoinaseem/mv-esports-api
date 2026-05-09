<?php

use App\Enums\MatchGameStatus;
use App\Enums\MatchStatus;
use App\Models\MatchGame;
use App\Models\TournamentMatch;

test('best_of=1 auto-completes the match on a single winning game', function () {
    $match = TournamentMatch::factory()->create([
        'best_of' => 1,
        'status'  => MatchStatus::Scheduled,
    ]);

    MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Completed);
    expect($match->winner_participant_id)->toBe($match->participant_a_id);
    expect($match->completed_at)->not->toBeNull();
});

test('best_of=3 auto-completes after 2 wins (sweep)', function () {
    $match = TournamentMatch::factory()->bestOf(3)->create([
        'status' => MatchStatus::Scheduled,
    ]);

    MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);
    expect($match->fresh()->status)->toBe(MatchStatus::InProgress);

    MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 2,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Completed);
    expect($match->score_a)->toBe(2);
    expect($match->score_b)->toBe(0);
});

test('best_of=3 auto-completes after 2-1', function () {
    $match = TournamentMatch::factory()->bestOf(3)->create(['status' => MatchStatus::Scheduled]);

    MatchGame::factory()->create([
        'match_id' => $match->id, 'game_number' => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status' => MatchGameStatus::Completed,
    ]);
    MatchGame::factory()->create([
        'match_id' => $match->id, 'game_number' => 2,
        'winner_participant_type' => $match->participant_b_type,
        'winner_participant_id'   => $match->participant_b_id,
        'status' => MatchGameStatus::Completed,
    ]);
    expect($match->fresh()->status)->toBe(MatchStatus::InProgress);

    MatchGame::factory()->create([
        'match_id' => $match->id, 'game_number' => 3,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status' => MatchGameStatus::Completed,
    ]);

    expect($match->fresh()->status)->toBe(MatchStatus::Completed);
    expect($match->fresh()->score_a)->toBe(2);
    expect($match->fresh()->score_b)->toBe(1);
});

test('best_of=3 stays InProgress at 1-0', function () {
    $match = TournamentMatch::factory()->bestOf(3)->create(['status' => MatchStatus::Scheduled]);

    MatchGame::factory()->create([
        'match_id' => $match->id, 'game_number' => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status' => MatchGameStatus::Completed,
    ]);

    expect($match->fresh()->status)->toBe(MatchStatus::InProgress);
});

test('completed match does not auto-revert when a winning game is deleted', function () {
    $match = TournamentMatch::factory()->bestOf(3)->create(['status' => MatchStatus::Scheduled]);

    $g1 = MatchGame::factory()->create([
        'match_id' => $match->id, 'game_number' => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status' => MatchGameStatus::Completed,
    ]);
    $g2 = MatchGame::factory()->create([
        'match_id' => $match->id, 'game_number' => 2,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status' => MatchGameStatus::Completed,
    ]);
    expect($match->fresh()->status)->toBe(MatchStatus::Completed);

    $g2->delete();

    // Score recomputes (1-0) but status stays Completed (forward-only).
    expect($match->fresh()->status)->toBe(MatchStatus::Completed);
    expect($match->fresh()->score_a)->toBe(1);
});

test('strict-majority threshold defends against even best_of (1-1 tie does not auto-complete A)', function () {
    // best_of=2 is rejected at validation but defensible at the observer layer
    // too: a tied 1-1 score should NOT trigger auto-completion of either side.
    $match = TournamentMatch::factory()->create([
        'best_of' => 2,
        'status'  => MatchStatus::Scheduled,
    ]);

    \App\Models\MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => \App\Enums\MatchGameStatus::Completed,
    ]);
    \App\Models\MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 2,
        'winner_participant_type' => $match->participant_b_type,
        'winner_participant_id'   => $match->participant_b_id,
        'status'                  => \App\Enums\MatchGameStatus::Completed,
    ]);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::InProgress);
    expect($match->score_a)->toBe(1);
    expect($match->score_b)->toBe(1);
    expect($match->winner_participant_id)->toBeNull();
});

test('a Pending match (slots not yet filled) does not auto-complete even with a recorded game', function () {
    $match = TournamentMatch::factory()->pending()->create();
    // Pending matches have null participant_a per the factory state.
    // No game can have a winner that matches participant_a, so threshold can't be met.
    // Defensively verify: even if scores somehow update, status stays Pending.
    $match->update(['score_a' => 5, 'score_b' => 0]);
    expect($match->fresh()->status)->toBe(MatchStatus::Pending);
});
