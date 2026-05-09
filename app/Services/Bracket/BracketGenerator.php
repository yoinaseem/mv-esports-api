<?php

namespace App\Services\Bracket;

use App\Models\Stage;

/**
 * Common interface for the format-specific bracket generators. Each
 * implementation reads the populated `stage_participants` for a stage
 * and writes `matches` rows describing the bracket. The orchestrator
 * (`SeedAndBuildService`) dispatches to the right one by stage format.
 */
interface BracketGenerator
{
    /**
     * Generate matches for $stage. Assumes participants are already populated.
     * Returns a small summary: ['matches_generated' => int, 'byes_assigned' => int].
     */
    public function generate(Stage $stage): array;
}
