<?php

namespace App\Services\Bracket;

use App\Enums\RegistrationStatus;
use App\Enums\StageParticipantStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Tournament;
use Illuminate\Support\Collection;

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

        $hypothetical = $this->compute($tournament, $stage);
        $created      = 0;

        foreach ($hypothetical as $sp) {
            // Persist by replaying create() — using the unsaved instance's
            // attributes. StageParticipant::create() is the canonical write
            // path; bypassing it via $sp->save() would skip any future
            // observer/event hooks.
            StageParticipant::create([
                'stage_id'         => $sp->stage_id,
                'participant_type' => $sp->participant_type,
                'participant_id'   => $sp->participant_id,
                'seed'             => $sp->seed,
                'status'           => $sp->status,
            ]);
            $created++;
        }
        return $created;
    }

    /**
     * Pure analogue of `resolve()` — computes what `stage_participants` rows
     * WOULD be created for $stage given $tournament's current approved
     * registrations, without persisting anything. Returns a Collection of
     * unsaved `StageParticipant` instances each carrying a transient
     * `_registration_id` attribute so callers (the preview endpoint) can
     * link back to the source registration.
     *
     * Sort order mirrors `resolve()`: explicit seed ASC (NULLS LAST), then
     * `registered_at`, then `id`. Final seeds are 1..N with no gaps.
     *
     * Pass `$loadParticipants = true` when the caller needs the morphed
     * team / player data attached for response serialisation; default
     * is off so `resolve()` doesn't pay for it.
     *
     * @return Collection<int, StageParticipant>
     */
    public function compute(Tournament $tournament, Stage $stage, bool $loadParticipants = false): Collection
    {
        $query = $tournament->registrations()
            ->where('status', RegistrationStatus::Approved->value);

        if ($loadParticipants) {
            $query->with('participant');
        }

        $registrations = $query->get();

        $ordered = $registrations->sort(function ($a, $b) {
            $cmp = ($a->seed ?? PHP_INT_MAX) <=> ($b->seed ?? PHP_INT_MAX);
            if ($cmp !== 0) return $cmp;
            return ($a->registered_at <=> $b->registered_at)
                ?: ($a->id <=> $b->id);
        })->values();

        return $ordered->map(function ($reg, $i) use ($stage, $loadParticipants) {
            $sp = new StageParticipant([
                'stage_id'         => $stage->id,
                'participant_type' => $reg->participant_type,
                'participant_id'   => $reg->participant_id,
                'seed'             => $i + 1,
                'status'           => StageParticipantStatus::Active,
            ]);
            // Transient metadata for the preview response — not persisted.
            $sp->setAttribute('_registration_id', $reg->id);
            if ($loadParticipants) {
                $sp->setRelation('participant', $reg->participant);
            }
            return $sp;
        });
    }
}
