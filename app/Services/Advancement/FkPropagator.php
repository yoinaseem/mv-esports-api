<?php

namespace App\Services\Advancement;

use App\Enums\MatchStatus;
use App\Models\TournamentMatch;
use App\Services\Match\MatchEventLogger;

/**
 * Propagates a single just-completed match's winner / loser through its
 * advancement FKs into the appropriate slots of the target matches. Pure
 * primitive — does not check stage completion, does not cascade.
 *
 * Used both directly (for walkover-bye propagation at bracket-build time)
 * and as the first step of the full advancement orchestrator.
 */
class FkPropagator
{
    public function __construct(
        private readonly MatchEventLogger $logger,
    ) {}

    /**
     * Propagate $match's outcome to its advancement targets. Idempotent
     * if the targets are already populated (will throw on slot conflict).
     */
    public function propagate(TournamentMatch $match): void
    {
        // Cancelled matches don't propagate — there's no winner to send forward.
        if ($match->status === MatchStatus::Cancelled) {
            return;
        }

        // Only terminal matches with a known winner propagate. Pending /
        // Scheduled / InProgress are no-ops (defensive — should never
        // be called with a non-terminal match anyway).
        if (! $match->status->isTerminal() || $match->winner_participant_id === null) {
            return;
        }

        $this->propagateWinnerIfTargetSet($match);
        $this->propagateLoserIfTargetSet($match);
    }

    /**
     * Propagate every terminal match in $stage. Used by the seed-and-build
     * flow after the bracket generator creates byes (matches in Walkover
     * status from creation). Single-pass — does not re-check after
     * propagation since byes only ever fill round-2 slots and don't
     * auto-complete those targets (no game results yet).
     */
    public function propagateAllTerminalIn(\App\Models\Stage $stage): void
    {
        $matches = $stage->matches()
            ->whereIn('status', [
                MatchStatus::Completed->value,
                MatchStatus::Walkover->value,
            ])
            ->get();

        foreach ($matches as $m) {
            $this->propagate($m);
        }
    }

    private function propagateWinnerIfTargetSet(TournamentMatch $match): void
    {
        if ($match->winner_advances_to_match_id === null) {
            return;
        }

        $target = TournamentMatch::find($match->winner_advances_to_match_id);
        if ($target === null) {
            return; // FK already SET-NULLed by a deletion; nothing to do
        }

        $slot = $match->winner_advances_to_slot;
        $this->fillSlot(
            $target,
            $slot,
            $match->winner_participant_type,
            $match->winner_participant_id,
        );
    }

    private function propagateLoserIfTargetSet(TournamentMatch $match): void
    {
        if ($match->loser_advances_to_match_id === null) {
            return;
        }

        $target = TournamentMatch::find($match->loser_advances_to_match_id);
        if ($target === null) {
            return;
        }

        // Compute the loser by exclusion. Walkover matches with a null
        // participant_b have no loser to forward.
        [$loserType, $loserId] = $this->computeLoser($match);
        if ($loserId === null) {
            return;
        }

        $slot = $match->loser_advances_to_slot;
        $this->fillSlot($target, $slot, $loserType, $loserId);
    }

    private function fillSlot(
        TournamentMatch $target,
        string $slot,
        string $participantType,
        int $participantId,
    ): void {
        $typeColumn = "participant_{$slot}_type";
        $idColumn   = "participant_{$slot}_id";

        // Defensive: if the slot is already populated with a different
        // participant, we have an FK-graph wiring bug. Throw so the
        // transaction rolls back and the host sees a clear error.
        if ($target->{$idColumn} !== null
            && ((int) $target->{$idColumn} !== (int) $participantId
                || $target->{$typeColumn} !== $participantType)) {
            throw new \DomainException(sprintf(
                'Match %d slot %s is already filled with %s#%d; cannot overwrite with %s#%d.',
                $target->id,
                $slot,
                $target->{$typeColumn},
                $target->{$idColumn},
                $participantType,
                $participantId,
            ));
        }

        // Idempotent: if already filled with the same participant, no-op.
        if ($target->{$idColumn} !== null) {
            return;
        }

        $target->update([
            $typeColumn => $participantType,
            $idColumn   => $participantId,
        ]);

        $this->logger->logParticipantAssigned(
            $target,
            null, // system-emitted
            $slot,
            $participantType,
            $participantId,
        );

        // If the target now has both slots filled and is in Pending /
        // Conditional, transition forward.
        $target->refresh();
        $bothSlotsFilled = $target->participant_a_id !== null
            && $target->participant_b_id !== null;
        if ($bothSlotsFilled && $target->status === MatchStatus::Pending) {
            $previousStatus = $target->status;
            $target->update(['status' => MatchStatus::Scheduled]);
            $this->logger->logStatusChange($target, null, $previousStatus, MatchStatus::Scheduled);
        }
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function computeLoser(TournamentMatch $match): array
    {
        // For walkovers / byes with only one participant, no loser to forward.
        if ($match->participant_a_id === null || $match->participant_b_id === null) {
            return [null, null];
        }

        if ((int) $match->winner_participant_id === (int) $match->participant_a_id
            && $match->winner_participant_type === $match->participant_a_type) {
            return [$match->participant_b_type, $match->participant_b_id];
        }

        return [$match->participant_a_type, $match->participant_a_id];
    }
}
