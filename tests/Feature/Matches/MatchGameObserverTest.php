<?php

use App\Enums\MatchGameStatus;
use App\Models\MatchGame;
use App\Models\TournamentMatch;

test('saving a winning game increments the winners side score on the parent match', function () {
    $match = TournamentMatch::factory()->bestOf(3)->create();

    MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);

    $match->refresh();
    expect($match->score_a)->toBe(1);
    expect($match->score_b)->toBe(0);
});

test('multiple games sum correctly per side', function () {
    $match = TournamentMatch::factory()->bestOf(5)->create();

    MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);
    MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 2,
        'winner_participant_type' => $match->participant_b_type,
        'winner_participant_id'   => $match->participant_b_id,
        'status'                  => MatchGameStatus::Completed,
    ]);
    MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 3,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);

    $match->refresh();
    expect($match->score_a)->toBe(2);
    expect($match->score_b)->toBe(1);
});

test('deleting a game decrements the parent match score', function () {
    $match = TournamentMatch::factory()->bestOf(3)->create();

    $game = MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);

    expect($match->fresh()->score_a)->toBe(1);

    $game->delete();

    expect($match->fresh()->score_a)->toBe(0);
});

test('updating a game winner reflects on the parent match', function () {
    $match = TournamentMatch::factory()->bestOf(3)->create();

    $game = MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);

    expect($match->fresh()->score_a)->toBe(1);
    expect($match->fresh()->score_b)->toBe(0);

    $game->update([
        'winner_participant_type' => $match->participant_b_type,
        'winner_participant_id'   => $match->participant_b_id,
    ]);

    $match->refresh();
    expect($match->score_a)->toBe(0);
    expect($match->score_b)->toBe(1);
});

test('a pending (no winner) game does not increment either score', function () {
    $match = TournamentMatch::factory()->bestOf(3)->create();

    MatchGame::factory()->create([
        'match_id'    => $match->id,
        'game_number' => 1,
        'status'      => MatchGameStatus::Pending,
    ]);

    $match->refresh();
    expect($match->score_a)->toBe(0);
    expect($match->score_b)->toBe(0);
});
