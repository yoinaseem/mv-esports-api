<?php

use App\Enums\MatchEventType;
use App\Models\MatchEvent;
use App\Models\TournamentMatch;

use function Pest\Laravel\getJson;

test('index returns a match event feed sorted ascending by created_at', function () {
    $match = TournamentMatch::factory()->create();

    MatchEvent::factory()->create([
        'match_id'   => $match->id,
        'event_type' => MatchEventType::ScoreUpdate,
        'payload'    => ['score_a' => 1, 'score_b' => 0],
        'created_at' => now()->subMinutes(10),
    ]);
    MatchEvent::factory()->create([
        'match_id'   => $match->id,
        'event_type' => MatchEventType::ScoreUpdate,
        'payload'    => ['score_a' => 2, 'score_b' => 0],
        'created_at' => now()->subMinutes(5),
    ]);
    MatchEvent::factory()->create([
        'match_id'   => $match->id,
        'event_type' => MatchEventType::ScoreUpdate,
        'payload'    => ['score_a' => 2, 'score_b' => 1],
        'created_at' => now(),
    ]);

    $r = getJson("/api/matches/{$match->id}/events")
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect($r->json('data.0.payload.score_a'))->toBe(1);
    expect($r->json('data.2.payload.score_b'))->toBe(1);
});

test('index ?since= filters out events older than or equal to the timestamp', function () {
    $match = TournamentMatch::factory()->create();

    MatchEvent::factory()->create([
        'match_id'   => $match->id,
        'event_type' => MatchEventType::ScoreUpdate,
        'created_at' => now()->subMinutes(10),
    ]);
    MatchEvent::factory()->create([
        'match_id'   => $match->id,
        'event_type' => MatchEventType::ScoreUpdate,
        'created_at' => now()->subMinutes(2),
    ]);

    $cutoff = urlencode(now()->subMinutes(5)->toIso8601String());

    getJson("/api/matches/{$match->id}/events?since={$cutoff}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('index returns events scoped to the match only (no leakage across matches)', function () {
    $matchA = TournamentMatch::factory()->create();
    $matchB = TournamentMatch::factory()->create();

    MatchEvent::factory()->count(2)->create(['match_id' => $matchA->id]);
    MatchEvent::factory()->count(3)->create(['match_id' => $matchB->id]);

    getJson("/api/matches/{$matchA->id}/events")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('event payload shape includes the type, payload, created_at, and creator id', function () {
    $match = TournamentMatch::factory()->create();
    MatchEvent::factory()->create([
        'match_id'   => $match->id,
        'event_type' => MatchEventType::StatusChange,
        'payload'    => ['from' => 'pending', 'to' => 'scheduled'],
    ]);

    $r = getJson("/api/matches/{$match->id}/events")->assertOk();
    $event = $r->json('data.0');

    expect($event)->toHaveKeys(['id', 'event_type', 'payload', 'created_at', 'created_by_user_id']);
    expect($event['event_type'])->toBe('status_change');
});
