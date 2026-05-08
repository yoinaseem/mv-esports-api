<?php

namespace App\Enums;

/**
 * Stage-participant lifecycle. Tracks whether a participant is still
 * competing in a stage (and through it, in the tournament).
 *
 *    [*]          ──participant slotted into stage──>    Active
 *    Active       ──eliminated by match outcome──>       Eliminated  (commit 9)
 *    Active       ──participant withdraws──>             Withdrawn   (commit 9)
 *
 * Status transitions are driven by services (match advancement, manual
 * withdrawal) — commit 6 ships the enum but no endpoint exposes the
 * transitions directly except via the generic stage_participants PATCH.
 */
enum StageParticipantStatus: string
{
    case Active     = 'active';
    case Eliminated = 'eliminated';
    case Withdrawn  = 'withdrawn';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Active => in_array($next, [self::Eliminated, self::Withdrawn], true),
            default      => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Eliminated || $this === self::Withdrawn;
    }
}
