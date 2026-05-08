<?php

namespace App\Enums;

/**
 * Event types for match_events. Drives the polling-based live feed
 * (DESIGN.md §2). Append-only — no transitions.
 */
enum MatchEventType: string
{
    case ScoreUpdate         = 'score_update';
    case StatusChange        = 'status_change';
    case WalkoverCalled      = 'walkover_called';
    case ParticipantAssigned = 'participant_assigned';
    case GameCompleted       = 'game_completed';
}
