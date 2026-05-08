<?php

namespace App\Enums;

/**
 * Match lifecycle status. Diagram in DIAGRAMS.md §8.
 *
 *    [*]                                 ──> Pending     (slots empty or partial)
 *    [*] (grand-final reset, on creation) ──> Conditional
 *
 *    Pending      ──both slots filled──>     Scheduled
 *    Conditional  ──reset match activated──> Pending      (L-bracket finalist won the GF)
 *    Conditional  ──W-bracket finalist won GF──> Cancelled
 *    Scheduled    ──first game starts──>     InProgress
 *    Scheduled    ──opponent forfeits──>     Walkover
 *    InProgress   ──best_of threshold──>     Completed
 *    InProgress   ──forfeit mid-series──>    Walkover
 *    {non-terminal} ──manager intervenes──>  Cancelled
 *
 * Terminal: Completed, Walkover, Cancelled.
 */
enum MatchStatus: string
{
    case Pending     = 'pending';
    case Scheduled   = 'scheduled';
    case InProgress  = 'in_progress';
    case Completed   = 'completed';
    case Walkover    = 'walkover';
    case Conditional = 'conditional';
    case Cancelled   = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        if ($this->isTerminal()) {
            return false;
        }

        // Universal escape: any non-terminal can be cancelled.
        if ($next === self::Cancelled) {
            return true;
        }

        return match ($this) {
            self::Pending     => $next === self::Scheduled,
            self::Conditional => $next === self::Pending,        // reset match activated
            self::Scheduled   => in_array($next, [self::InProgress, self::Walkover], true),
            self::InProgress  => in_array($next, [self::Completed, self::Walkover], true),
            default           => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Walkover, self::Cancelled], true);
    }
}
