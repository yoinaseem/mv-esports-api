<?php

use App\Enums\MatchEventType;
use App\Enums\MatchGameStatus;
use App\Enums\MatchStatus;
use App\Models\MatchEvent;
use App\Models\MatchGame;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\Match\MatchEventLogger;

beforeEach(function () {
    $this->logger = app(MatchEventLogger::class);
});

test('logScoreUpdate creates a score_update event with the given payload', function () {
    $match = TournamentMatch::factory()->create();
    $user  = User::factory()->create();

    $event = $this->logger->logScoreUpdate($match, $user, ['score_a' => 1, 'score_b' => 2]);

    expect($event)->toBeInstanceOf(MatchEvent::class);
    expect($event->event_type)->toBe(MatchEventType::ScoreUpdate);
    expect($event->payload)->toBe(['score_a' => 1, 'score_b' => 2]);
    expect($event->created_by_user_id)->toBe($user->id);
    expect($event->match_id)->toBe($match->id);
});

test('logStatusChange records from and to status values in the payload', function () {
    $match = TournamentMatch::factory()->create();
    $user  = User::factory()->create();

    $event = $this->logger->logStatusChange($match, $user, MatchStatus::Pending, MatchStatus::Scheduled);

    expect($event->event_type)->toBe(MatchEventType::StatusChange);
    expect($event->payload)->toBe(['from' => 'pending', 'to' => 'scheduled']);
});

test('logWalkoverCalled creates a walkover_called event', function () {
    $match = TournamentMatch::factory()->create();
    $user  = User::factory()->create();

    $event = $this->logger->logWalkoverCalled($match, $user, ['reason' => 'no-show']);

    expect($event->event_type)->toBe(MatchEventType::WalkoverCalled);
    expect($event->payload)->toBe(['reason' => 'no-show']);
});

test('logParticipantAssigned encodes slot and participant identity', function () {
    $match = TournamentMatch::factory()->create();
    $user  = User::factory()->create();

    $event = $this->logger->logParticipantAssigned($match, $user, 'a', 'team', 42);

    expect($event->event_type)->toBe(MatchEventType::ParticipantAssigned);
    expect($event->payload)->toBe([
        'slot'             => 'a',
        'participant_type' => 'team',
        'participant_id'   => 42,
    ]);
});

test('logGameCompleted captures game id, score, and winner', function () {
    $match = TournamentMatch::factory()->create();
    $user  = User::factory()->create();

    $game = MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'score_a'                 => 13,
        'score_b'                 => 7,
        'map_or_mode'             => 'haven',
        'status'                  => MatchGameStatus::Completed,
    ]);

    $event = $this->logger->logGameCompleted($game->fresh(), $user);

    expect($event->event_type)->toBe(MatchEventType::GameCompleted);
    expect($event->payload['game_id'])->toBe($game->id);
    expect($event->payload['game_number'])->toBe(1);
    expect($event->payload['score_a'])->toBe(13);
    expect($event->payload['score_b'])->toBe(7);
    expect($event->payload['map_or_mode'])->toBe('haven');
});

test('null user produces a system-emitted event', function () {
    $match = TournamentMatch::factory()->create();

    $event = $this->logger->logScoreUpdate($match, null, ['score_a' => 0, 'score_b' => 0]);

    expect($event->created_by_user_id)->toBeNull();
});
