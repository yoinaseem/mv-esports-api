<?php

use App\Models\Game;
use Illuminate\Database\QueryException;

test('a game can be created via factory with is_active defaulting to true', function () {
    $game = Game::factory()->create();

    expect($game->is_active)->toBeTrue();
    expect($game->slug)->not->toBeEmpty();
    expect($game->id)->toBeGreaterThan(0);
});

test('the inactive() factory state flips is_active to false', function () {
    $game = Game::factory()->inactive()->create();

    expect($game->is_active)->toBeFalse();
});

test('two games cannot share a slug (unique constraint)', function () {
    Game::factory()->create(['slug' => 'valorant']);

    expect(fn () => Game::factory()->create(['slug' => 'valorant']))
        ->toThrow(QueryException::class);
});

test('is_active casts to a boolean even when stored as 0/1', function () {
    $game = Game::factory()->create();
    $game->update(['is_active' => 0]);
    $game->refresh();

    expect($game->is_active)->toBeBool()->toBeFalse();
});
