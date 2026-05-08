<?php

namespace App\Enums;

/**
 * Per-game scoring lifecycle.
 *
 *    Pending → InProgress → Completed
 *
 * No cancelled state — if a game gets recorded incorrectly, the host
 * deletes the match_game row and re-creates it.
 */
enum MatchGameStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Completed  = 'completed';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Pending    => $next === self::InProgress || $next === self::Completed,
            self::InProgress => $next === self::Completed,
            default          => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed;
    }
}
