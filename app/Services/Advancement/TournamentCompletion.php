<?php

namespace App\Services\Advancement;

use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;

/**
 * Closes the tournament when all stages have completed. Trivial in
 * isolation; lives in its own service for symmetry with the other
 * advancement helpers and to keep the orchestrator small.
 */
class TournamentCompletion
{
    public function checkAndClose(Tournament $tournament): void
    {
        $hasIncomplete = $tournament->stages()
            ->where('status', '!=', StageStatus::Completed->value)
            ->exists();

        if ($hasIncomplete) {
            return;
        }

        if ($tournament->status->canTransitionTo(TournamentStatus::Completed)) {
            $tournament->update([
                'status'       => TournamentStatus::Completed,
                'completed_at' => now(),
            ]);
        }
    }
}
