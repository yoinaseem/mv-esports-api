<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a player belongs to a user and a game', function () {
    $player = Player::factory()->create();

    expect($player->user)->toBeInstanceOf(User::class);
    expect($player->game)->toBeInstanceOf(Game::class);
});

test('a user can have multiple players, one per distinct game', function () {
    $user  = User::factory()->create();
    $game1 = Game::factory()->create();
    $game2 = Game::factory()->create();

    Player::factory()->for($user)->for($game1)->create();
    Player::factory()->for($user)->for($game2)->create();

    expect($user->players()->count())->toBe(2);
});

test('a user cannot have two players for the same game (unique constraint)', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create();

    Player::factory()->for($user)->for($game)->create();

    expect(fn () => Player::factory()->for($user)->for($game)->create())
        ->toThrow(QueryException::class);
});

test('two players cannot share a gamertag within the same game', function () {
    $game = Game::factory()->create();
    Player::factory()->for($game)->create(['gamertag' => 'TheChosenOne']);

    expect(fn () => Player::factory()->for($game)->create(['gamertag' => 'TheChosenOne']))
        ->toThrow(QueryException::class);
});

test('the same gamertag may be reused across different games', function () {
    $game1 = Game::factory()->create();
    $game2 = Game::factory()->create();

    Player::factory()->for($game1)->create(['gamertag' => 'CrossGameTag']);
    $second = Player::factory()->for($game2)->create(['gamertag' => 'CrossGameTag']);

    expect($second->id)->toBeGreaterThan(0);
});

test('orphan players (user_id null) are allowed and do not collide on the user_id+game_id index', function () {
    $game = Game::factory()->create();

    Player::factory()->orphan()->for($game)->create();
    $second = Player::factory()->orphan()->for($game)->create();

    expect($second->user_id)->toBeNull();
    expect(Player::where('game_id', $game->id)->whereNull('user_id')->count())->toBe(2);
});

test('deleting a user nulls out their players user_id (soft anonymisation primitive)', function () {
    $user   = User::factory()->create();
    $player = Player::factory()->for($user)->create();

    $user->forceDelete();
    $player->refresh();

    expect($player->user_id)->toBeNull();
});

test('deleting a game with players is RESTRICTed (must move/delete players first)', function () {
    $game = Game::factory()->create();
    Player::factory()->for($game)->create();

    expect(fn () => $game->delete())->toThrow(QueryException::class);
});
