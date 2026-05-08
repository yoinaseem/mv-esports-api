<?php

namespace App\Services\Match;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\MatchEvent;
use App\Models\MatchGame;
use App\Models\TournamentMatch;
use App\Models\User;

/**
 * Single source of writes to match_events. Controllers and services
 * call the appropriate `log*` method after the relevant mutation; the
 * logger creates the immutable event row.
 *
 * Centralising emission means the live-feed schema is a single source
 * of truth — every endpoint that mutates a match also adds a feed
 * entry, and the entries' shape is consistent across event types.
 */
class MatchEventLogger
{
    public function logScoreUpdate(TournamentMatch $match, ?User $user, array $payload = []): MatchEvent
    {
        return $this->emit($match, MatchEventType::ScoreUpdate, $user, $payload);
    }

    public function logStatusChange(
        TournamentMatch $match,
        ?User $user,
        MatchStatus $from,
        MatchStatus $to,
    ): MatchEvent {
        return $this->emit($match, MatchEventType::StatusChange, $user, [
            'from' => $from->value,
            'to'   => $to->value,
        ]);
    }

    public function logWalkoverCalled(TournamentMatch $match, ?User $user, array $payload = []): MatchEvent
    {
        return $this->emit($match, MatchEventType::WalkoverCalled, $user, $payload);
    }

    public function logParticipantAssigned(
        TournamentMatch $match,
        ?User $user,
        string $slot,             // 'a' | 'b'
        string $participantType,
        int $participantId,
    ): MatchEvent {
        return $this->emit($match, MatchEventType::ParticipantAssigned, $user, [
            'slot'             => $slot,
            'participant_type' => $participantType,
            'participant_id'   => $participantId,
        ]);
    }

    public function logGameCompleted(MatchGame $game, ?User $user): MatchEvent
    {
        return $this->emit($game->match, MatchEventType::GameCompleted, $user, [
            'game_id'                 => $game->id,
            'game_number'             => $game->game_number,
            'winner_participant_type' => $game->winner_participant_type,
            'winner_participant_id'   => $game->winner_participant_id,
            'score_a'                 => $game->score_a,
            'score_b'                 => $game->score_b,
            'map_or_mode'             => $game->map_or_mode,
        ]);
    }

    private function emit(
        TournamentMatch $match,
        MatchEventType $type,
        ?User $user,
        array $payload,
    ): MatchEvent {
        return MatchEvent::create([
            'match_id'           => $match->id,
            'event_type'         => $type->value,
            'payload'            => $payload,
            'created_by_user_id' => $user?->id,
            'created_at'         => now(),
        ]);
    }
}
