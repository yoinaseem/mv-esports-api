<?php

namespace App\Services\Stage;

use App\Enums\RegistrationStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\Tournament;
use Illuminate\Support\Collection;

/**
 * Cross-validates entry-stage invariants:
 *
 *   1. Entry-stage capacity vs `tournament.max_participants` (RR only)
 *      — strict equality (`groups × group_size === max_participants`)
 *      enforced **only at design time** (`Draft` / `DraftPendingReview`)
 *      and at the open-registration lock-in gate. Once registration has
 *      opened (`RegistrationOpen` / `RegistrationClosed`) the check
 *      relaxes: the host can adjust either knob as reality diverges
 *      from intent (e.g. attrition: declared 8, only 6 sign up, want
 *      a tidy 6-seat bracket without un-declaring max). The
 *      approved-count check (#2) carries the structural-safety load
 *      from that point.
 *
 *   1a. DE entry-stage `max_participants ∈ {4, 8, 16, 32}` — always
 *      enforced regardless of status, because the generator literally
 *      cannot build a DE bracket at other counts. SE has no fixed
 *      capacity (grows to next power of 2), so no check.
 *
 *   2. Entry-stage capacity vs current approved registration count —
 *      always enforced (RR only — DE is decided by max, SE auto-grows).
 *      Shrinking config below the approved count would strand approved
 *      players.
 *
 * Called from every write path that can violate any invariant: stage
 * PATCH, tournament PATCH (`max_participants`), qualification POST
 * (entry-stage promotion), and the open-registration verb endpoint
 * (lock-in gate before going live). Wrapped in a transaction by the
 * caller so a violation rolls the write back cleanly.
 */
class EntryStageCapacityValidator
{
    private const DE_VALID_SIZES = [4, 8, 16, 32];

    /**
     * Runs both checks against every entry stage. Aborts 422 on the first
     * violation, message naming the relevant knobs.
     */
    public function validate(Tournament $tournament): void
    {
        foreach ($this->entryStages($tournament) as $stage) {
            $this->checkAgainstMax($stage, $tournament);
            $this->checkAgainstApproved($stage, $tournament);
        }
    }

    /**
     * Statuses in which the RR `cap === max` check applies. Outside this
     * set the host is mid- or post-registration; reality (approved count)
     * matters more than intent (max).
     */
    private const STRICT_RR_CHECK_STATUSES = [
        TournamentStatus::DraftPendingReview,
        TournamentStatus::Draft,
    ];

    /**
     * Capacity vs `tournament.max_participants`. Format-specific:
     *  - RR: `groups × group_size === max_participants` (strict equality)
     *        only during Draft / DraftPendingReview. Relaxed once
     *        registration opens.
     *  - DE: `max_participants` itself must be in {4, 8, 16, 32}.
     *        Always checked (the generator can't build other sizes).
     *  - SE: always passes (bracket auto-grows).
     */
    private function checkAgainstMax(Stage $stage, Tournament $tournament): void
    {
        if ($tournament->max_participants === null) {
            return;
        }

        if ($stage->format === 'round_robin') {
            if (! in_array($tournament->status, self::STRICT_RR_CHECK_STATUSES, true)) {
                return; // post-design-time: approved-count check carries the load
            }

            $groups    = (int) ($stage->config['groups']     ?? 0);
            $groupSize = (int) ($stage->config['group_size'] ?? 0);
            $capacity  = $groups * $groupSize;

            if ($capacity !== $tournament->max_participants) {
                abort(422, sprintf(
                    'Entry stage "%s" seats %d (groups %d × group_size %d), but tournament max_participants is %d. For round_robin entry stages these must match at draft time — set the stage capacity to %d, or set max_participants to %d.',
                    $stage->name,
                    $capacity,
                    $groups,
                    $groupSize,
                    $tournament->max_participants,
                    $tournament->max_participants,
                    $capacity,
                ));
            }

            return;
        }

        if ($stage->format === 'double_elim') {
            if (! in_array($tournament->max_participants, self::DE_VALID_SIZES, true)) {
                abort(422, sprintf(
                    'Entry stage "%s" is double_elim, which only supports participant counts of %s. Either change max_participants from %d to one of those, or change the stage format.',
                    $stage->name,
                    implode(', ', self::DE_VALID_SIZES),
                    $tournament->max_participants,
                ));
            }
        }
    }

    /**
     * Capacity vs current approved registration count. Only applies to RR
     * entry stages — DE capacity is determined by max_participants
     * directly (covered by checkAgainstMax), and SE auto-grows.
     */
    private function checkAgainstApproved(Stage $stage, Tournament $tournament): void
    {
        if ($stage->format !== 'round_robin') {
            return;
        }

        $groups    = (int) ($stage->config['groups']     ?? 0);
        $groupSize = (int) ($stage->config['group_size'] ?? 0);
        $capacity  = $groups * $groupSize;

        $approved = $tournament->registrations()
            ->where('status', RegistrationStatus::Approved->value)
            ->count();

        if ($approved > 0 && $capacity < $approved) {
            abort(422, sprintf(
                'Entry stage "%s" seats only %d, but %d registrations are already approved. Either reject %d to fit, or expand the stage to seat at least %d.',
                $stage->name,
                $capacity,
                $approved,
                $approved - $capacity,
                $approved,
            ));
        }
    }

    /**
     * Stages that pull participants directly from registrations
     * (incoming qualification with source_stage_id=null and rule=all).
     *
     * @return Collection<int, Stage>
     */
    private function entryStages(Tournament $tournament): Collection
    {
        return $tournament->stages()
            ->whereHas('incomingQualifications', fn ($q) =>
                $q->whereNull('source_stage_id')->where('rule_type', 'all'))
            ->get();
    }
}
