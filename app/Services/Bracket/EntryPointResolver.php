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
            ->orderBy('seed')
            ->get();

        $created = 0;
        foreach ($registrations as $reg) {
            StageParticipant::create([
                'stage_id'         => $stage->id,
                'participant_type' => $reg->participant_type,
                'participant_id'   => $reg->participant_id,
                'seed'             => $reg->seed,
                'status'           => StageParticipantStatus::Active,
            ]);
            $created++;
        }
        return $created;
    }
}
