<?php

namespace App\Enums;

/**
 * Tournament registration lifecycle. Smaller surface than TournamentStatus
 * — registrations don't have a multi-stage workflow.
 *
 *    [*] ──participant submits──> Pending
 *    Pending ──host approves──> Approved
 *    Pending ──host rejects──> Rejected
 *    Approved ──participant withdraws──> Withdrawn
 *    Pending ──participant withdraws (before approval)──> Withdrawn
 */
enum RegistrationStatus: string
{
    case Pending   = 'pending';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Withdrawn = 'withdrawn';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        if ($this->isTerminal()) {
            return false;
        }

        return match ($this) {
            self::Pending  => in_array($next, [self::Approved, self::Rejected, self::Withdrawn], true),
            self::Approved => $next === self::Withdrawn,
            default        => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Withdrawn], true);
    }
}
