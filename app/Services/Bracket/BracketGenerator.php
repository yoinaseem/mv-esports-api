<?php

namespace App\Services\Bracket;

use App\Models\Stage;
use Illuminate\Support\Collection;

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

    /**
     * Dry-run analogue of `generate()` — computes the bracket layout that
     * WOULD be produced if `generate()` ran right now with the given
     * hypothetical participants, without persisting anything.
     *
     * `$participants` is a Collection of unsaved `StageParticipant`
     * instances (typically produced by `EntryPointResolver::compute()`).
     * The relation `participant` may be eager-loaded on each so the caller
     * can attach names to the response.
     *
     * Returns a format-specific structured array — see the per-generator
     * implementations for shape. Returns `['buildable' => false, 'reason' => ...]`
     * when the input violates a generator precondition (e.g., DE with a
     * non-power-of-2 count).
     *
     * @param Collection<int, \App\Models\StageParticipant>  $participants
     */
    public function preview(Stage $stage, Collection $participants): array;
}
