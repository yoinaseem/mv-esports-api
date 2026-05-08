<?php

use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Models\Organization;
use App\Models\Tournament;
use App\Models\TournamentHost;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a tournament casts status as a backed enum', function () {
    $tournament = Tournament::factory()->create();

    expect($tournament->status)->toBeInstanceOf(TournamentStatus::class);
    expect($tournament->status)->toBe(TournamentStatus::DraftPendingReview);
});

test('relationships resolve: game / host / organization / creator', function () {
    $game    = Game::factory()->create();
    $host    = TournamentHost::factory()->create();
    $org     = Organization::factory()->create();
    $creator = User::factory()->create();

    $t = Tournament::factory()->create([
        'game_id'            => $game->id,
        'host_id'            => $host->id,
        'organization_id'    => $org->id,
        'created_by_user_id' => $creator->id,
    ]);

    expect($t->game->id)->toBe($game->id);
    expect($t->host->id)->toBe($host->id);
    expect($t->organization->id)->toBe($org->id);
    expect($t->creator->id)->toBe($creator->id);
});

test('two tournaments cannot share a slug', function () {
    Tournament::factory()->create(['slug' => 'taken']);
    expect(fn () => Tournament::factory()->create(['slug' => 'taken']))
        ->toThrow(QueryException::class);
});

test('soft-deleting a tournament keeps it recoverable', function () {
    $t  = Tournament::factory()->create();
    $id = $t->id;
    $t->delete();

    expect(Tournament::find($id))->toBeNull();
    expect(Tournament::withTrashed()->find($id))->not->toBeNull();
});

test('deleting a host nulls host_id without dropping the tournament', function () {
    $host = TournamentHost::factory()->create();
    $t    = Tournament::factory()->create(['host_id' => $host->id]);

    $host->delete();
    $t->refresh();

    expect($t->host_id)->toBeNull();
});

test('deleting an organization nulls organization_id without dropping the tournament', function () {
    $org = Organization::factory()->create();
    $t   = Tournament::factory()->create(['organization_id' => $org->id]);

    $org->forceDelete();
    $t->refresh();

    expect($t->organization_id)->toBeNull();
});

test('hard-deleting the creator nulls created_by_user_id without dropping the tournament', function () {
    $creator = User::factory()->create();
    $t       = Tournament::factory()->create(['created_by_user_id' => $creator->id]);

    $creator->forceDelete();
    $t->refresh();

    expect($t->created_by_user_id)->toBeNull();
});

test('deleting a game with tournaments is RESTRICTed', function () {
    $game = Game::factory()->create();
    Tournament::factory()->create(['game_id' => $game->id]);

    expect(fn () => $game->delete())->toThrow(QueryException::class);
});

test('all factory state methods set the corresponding status', function () {
    expect(Tournament::factory()->draft()->create()->status)->toBe(TournamentStatus::Draft);
    expect(Tournament::factory()->registrationOpen()->create()->status)->toBe(TournamentStatus::RegistrationOpen);
    expect(Tournament::factory()->registrationClosed()->create()->status)->toBe(TournamentStatus::RegistrationClosed);
    expect(Tournament::factory()->inProgress()->create()->status)->toBe(TournamentStatus::InProgress);
    expect(Tournament::factory()->completed()->create()->status)->toBe(TournamentStatus::Completed);
    expect(Tournament::factory()->cancelled()->create()->status)->toBe(TournamentStatus::Cancelled);
});

test('participant_type defaults to team and accepts player', function () {
    expect(Tournament::factory()->create()->participant_type)->toBe('team');
    expect(Tournament::factory()->playerType()->create()->participant_type)->toBe('player');
});
