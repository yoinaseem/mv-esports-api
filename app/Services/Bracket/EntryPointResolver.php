<?php

namespace App\Services\Bracket;

use App\Enums\RegistrationStatus;
use App\Enums\StageParticipantStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Tournament;

/**
 * Copies a tournament's approved registrations into the entry stage's
 * `stage_participants` table so the bracket generator has a populated
 * roster to work from.
 *
 * Only fires for stages with a null-source `stage_qualifications` row
 * of `rule_type = 'all'`. Stages with `manual` rules are deliberately
 * skipped (host populated participants directly). Other rule types
 * from null source are rejected upstream by SeedAndBuildService's
 * preconditions.
 */
class EntryPointResolver
{
    /**
     * Run resolution for $stage in the context of $tournament. Returns
     * the count of stage_participants created.
     */
    public function resolve(Tournament $tournament, Stage $stage): int
    {
        // If the stage already has participants (manual population, prior
        // partial run), don't double-insert.
        if ($stage->participants()->exists()) {
            return 0;
        }

        $registrations = $tournament->registrations()
            ->where('status', RegistrationStatus::Approved->value)
            ->get();

        // Re-seed sequentially 1..N. `tournament_registrations.seed` is
        // nullable (host doesn't always assign one), but
        // `stage_participants.seed` is NOT NULL and the bracket generator
        // expects 1..N with no gaps. Sort by (registration's explicit seed
        // ASC NULLS LAST, then registered_at) so the host's intent is
        // preserved when set, and unseeded registrations fall back to
        // registration order. Final stage seeds are always 1..N.
        $ordered = $registrations->sort(function ($a, $b) {
            $cmp = ($a->seed ?? PHP_INT_MAX) <=> ($b->seed ?? PHP_INT_MAX);
            if ($cmp !== 0) return $cmp;
            return ($a->registered_at <=> $b->registered_at)
                ?: ($a->id <=> $b->id);
        })->values();

        $created = 0;
        foreach ($ordered as $i => $reg) {
            StageParticipant::create([
                'stage_id'         => $stage->id,
                'participant_type' => $reg->participant_type,
                'participant_id'   => $reg->participant_id,
                'seed'             => $i + 1,
                'status'           => StageParticipantStatus::Active,
            ]);
            $created++;
        }
        return $created;
    }
}
