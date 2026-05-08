<?php

use App\Enums\MatchEventType;
use App\Models\MatchEvent;
use App\Models\TournamentMatch;
use App\Models\User;

test('payload casts to array and event_type to enum', function () {
    $event = MatchEvent::factory()->create([
        'event_type' => MatchEventType::ScoreUpdate,
        'payload'    => ['score_a' => 1, 'score_b' => 0],
    ]);

    expect($event->event_type)->toBeInstanceOf(MatchEventType::class);
    expect($event->payload)->toBe(['score_a' => 1, 'score_b' => 0]);
});

test('events have no updated_at column', function () {
    $event = MatchEvent::factory()->create();

    // The model declares UPDATED_AT = null so the timestamp isn't tracked
    // and the column doesn't exist on the row.
    expect($event->getAttributes())->not->toHaveKey('updated_at');
});

test('match relation resolves to a TournamentMatch', function () {
    $match = TournamentMatch::factory()->create();
    $event = MatchEvent::factory()->create(['match_id' => $match->id]);

    expect($event->match)->toBeInstanceOf(TournamentMatch::class);
    expect($event->match->id)->toBe($match->id);
});

test('creator relation resolves to the User who emitted it', function () {
    $user  = User::factory()->create();
    $event = MatchEvent::factory()->create(['created_by_user_id' => $user->id]);

    expect($event->creator)->toBeInstanceOf(User::class);
    expect($event->creator->id)->toBe($user->id);
});

test('deleting a match cascades to its events', function () {
    $match = TournamentMatch::factory()->create();
    MatchEvent::factory()->count(3)->create(['match_id' => $match->id]);

    $match->forceDelete();

    expect(MatchEvent::where('match_id', $match->id)->count())->toBe(0);
});
