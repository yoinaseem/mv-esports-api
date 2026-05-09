<?php

namespace App\Services\Bracket;

use App\Models\Stage;

/**
 * Picks the right format-specific generator for a stage and runs it.
 *
 * Extracted from `SeedAndBuildService` so the same dispatch shape is
 * reusable from the match-advancement layer (when a downstream stage's
 * participants get populated by the qualification resolver, the
 * advancement service calls this dispatcher to build that stage's
 * bracket without re-implementing the format switch).
 */
class BracketGenerationDispatcher
{
    public function __construct(
        private readonly SingleEliminationGenerator $singleElim,
        private readonly DoubleEliminationGenerator $doubleElim,
        private readonly RoundRobinGenerator $roundRobin,
    ) {}

    /**
     * Generate matches for $stage. Returns the same summary shape the
     * generators return: ['matches_generated' => int, 'byes_assigned' => int].
     */
    public function dispatch(Stage $stage): array
    {
        $generator = match ($stage->format) {
            'single_elim' => $this->singleElim,
            'double_elim' => $this->doubleElim,
            'round_robin' => $this->roundRobin,
            'swiss'       => throw new \DomainException("Stage {$stage->id} uses swiss format, which is not implemented yet."),
            default       => throw new \DomainException("Stage {$stage->id} has unknown format '{$stage->format}'."),
        };

        return $generator->generate($stage);
    }
}
