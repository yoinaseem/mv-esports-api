<?php

namespace App\Enums;

/**
 * Tournament lifecycle status. Source of truth for legal transitions —
 * controllers and the model's setStatus path must consult canTransitionTo
 * before writing. Diagram in DIAGRAMS.md §7.
 *
 *    [*] ──host create──> DraftPendingReview
 *    [*] ──manager create (direct)──> Draft
 *
 *    DraftPendingReview ──manager approve──> Draft
 *    DraftPendingReview ──manager reject──> Cancelled
 *
 *    Draft ──host opens registration──> RegistrationOpen
 *    RegistrationOpen ──host/auto closes──> RegistrationClosed
 *    RegistrationClosed ──seeds + bracket generated──> InProgress     (commit 8/9)
 *    InProgress ──final match completes──> Completed                  (commit 9)
 *
 *    {any non-terminal} ──host or manager cancels──> Cancelled
 */
enum TournamentStatus: string
{
    case DraftPendingReview = 'draft_pending_review';
    case Draft              = 'draft';
    case RegistrationOpen   = 'registration_open';
    case RegistrationClosed = 'registration_closed';
    case InProgress         = 'in_progress';
    case Completed          = 'completed';
    case Cancelled          = 'cancelled';

    /**
     * Whether $this state may legally transition to $next.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        // Terminal states are sinks.
        if ($this->isTerminal()) {
            return false;
        }

        // Universal escape hatch: anything non-terminal may be cancelled.
        if ($next === self::Cancelled) {
            return true;
        }

        return match ($this) {
            self::DraftPendingReview => $next === self::Draft,
            self::Draft              => $next === self::RegistrationOpen,
            self::RegistrationOpen   => $next === self::RegistrationClosed,
            self::RegistrationClosed => $next === self::InProgress,
            self::InProgress         => $next === self::Completed,
            default                  => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /**
     * Public visibility threshold. Drafts (with or without review) are not
     * surfaced on viewer pages.
     */
    public function isPublic(): bool
    {
        return ! in_array($this, [self::DraftPendingReview, self::Draft], true);
    }
}
