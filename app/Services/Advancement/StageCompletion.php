<?php

namespace App\Services\Advancement;

use App\Enums\MatchStatus;
use App\Enums\StageStatus;
use App\Models\Stage;
use App\Services\Bracket\BracketGenerationDispatcher;

/**
 * Stage-level cascade. Triggered after every match transition to a
 * terminal state. If all matches in the stage are now terminal:
 *
 *   1. Compute final positions on stage_participants.
 *   2. Resolve downstream qualifications (populate downstream stage_participants).
 *   3. Generate brackets for the newly-populated downstream stages.
 *   4. Propagate any byes those new brackets created.
 *   5. Transition the just-completed stage from InProgress → Completed.
 *
 * The orchestrator (`MatchAdvancementService`) calls `checkAndClose` and
 * follows up with the tournament-completion check.
 */
class StageCompletion
{
    public function __construct(
        private readonly StandingsCalculator $standings,
        private readonly QualificationResolver $resolver,
        private readonly BracketGenerationDispatcher $bracketDispatcher,
        private readonly FkPropagator $propagator,
    ) {}

    public function checkAndClose(Stage $stage): void
    {
        if (! $this->allMatchesTerminal($stage)) {
            return;
        }

        // 1. Compute final positions.
        $this->standings->computeFor($stage);

        // 2. Resolve downstream qualifications.
        $newlyPopulated = $this->resolver->resolveDownstream($stage);

        // 3 + 4. Generate brackets and propagate byes for newly-populated downstream stages.
        foreach ($newlyPopulated as $downstream) {
            $this->bracketDispatcher->dispatch($downstream);
            $this->propagator->propagateAllTerminalIn($downstream);

            // Transition downstream stage Pending → InProgress.
            if ($downstream->status->canTransitionTo(StageStatus::InProgress)) {
                $downstream->update(['status' => StageStatus::InProgress]);
            }
        }

        // 5. Transition this stage to Completed.
        $stage->refresh();
        if ($stage->status->canTransitionTo(StageStatus::Completed)) {
            $stage->update(['status' => StageStatus::Completed]);
        }
    }

    private function allMatchesTerminal(Stage $stage): bool
    {
        $hasNonTerminal = $stage->matches()
            ->whereNotIn('status', [
                MatchStatus::Completed->value,
                MatchStatus::Walkover->value,
                MatchStatus::Cancelled->value,
            ])
            ->exists();

        return ! $hasNonTerminal;
    }
}
