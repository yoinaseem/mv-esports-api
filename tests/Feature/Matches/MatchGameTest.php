<?php

use App\Enums\MatchGameStatus;
use App\Models\MatchGame;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Database\QueryException;

test('a match game casts status as enum and resolves polymorphic winner', function () {
    $match = TournamentMatch::factory()->create();
    $game  = MatchGame::factory()->create([
        'match_id'                => $match->id,
        'winner_participant_type' => 'team',
        'winner_participant_id'   => $match->participant_a_id,
    ]);

    expect($game->status)->toBeInstanceOf(MatchGameStatus::class);
    expect($game->winner)->toBeInstanceOf(Team::class);
});

test('two games cannot share a (match_id, game_number)', function () {
    $match = TournamentMatch::factory()->create();
    MatchGame::factory()->create(['match_id' => $match->id, 'game_number' => 1]);

    expect(fn () => MatchGame::factory()->create(['match_id' => $match->id, 'game_number' => 1]))
        ->toThrow(QueryException::class);
});

test('the same game_number may be reused across different matches', function () {
    $matchA = TournamentMatch::factory()->create();
    $matchB = TournamentMatch::factory()->create();
    MatchGame::factory()->create(['match_id' => $matchA->id, 'game_number' => 1]);
    $second = MatchGame::factory()->create(['match_id' => $matchB->id, 'game_number' => 1]);

    expect($second->id)->toBeGreaterThan(0);
});

test('deleting a match cascades to its games', function () {
    $match = TournamentMatch::factory()->create();
    MatchGame::factory()->create(['match_id' => $match->id, 'game_number' => 1]);
    MatchGame::factory()->create(['match_id' => $match->id, 'game_number' => 2]);
    MatchGame::factory()->create(['match_id' => $match->id, 'game_number' => 3]);
    expect($match->games()->count())->toBe(3);

    $match->forceDelete();

    expect(MatchGame::where('match_id', $match->id)->count())->toBe(0);
});
