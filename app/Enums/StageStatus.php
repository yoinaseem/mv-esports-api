<?php

namespace App\Enums;

/**
 * Stage lifecycle status. Smaller surface than TournamentStatus — stages
 * have a linear progression with no cancelled state (cancellation
 * cascades from the tournament level).
 *
 *    [*]                                        ──> Pending
 *    Pending      ──bracket generated──>           InProgress  (commit 8)
 *    InProgress   ──final stage match completes──> Completed   (commit 9)
 *
 * Both forward transitions are fired by services in later commits;
 * commit 6 ships the enum knowing about them but no endpoint exposes
 * them directly.
 */
enum StageStatus: string
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
            self::Pending    => $next === self::InProgress,
            self::InProgress => $next === self::Completed,
            default          => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed;
    }
}
