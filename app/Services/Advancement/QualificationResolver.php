<?php

namespace App\Services\Advancement;

use App\Enums\StageParticipantStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\StageQualification;

/**
 * When an upstream stage completes, populate the participants of every
 * downstream stage that depends on it via stage_qualifications. Returns
 * the list of stages newly populated so the caller can fire bracket
 * generation for them.
 *
 * Rule type behavior:
 *   all              — copy every upstream stage_participant in
 *                      final_position order; assign seeds 1, 2, ... in that order.
 *   top_n            — top N by final_position; ties at the boundary include
 *                      everyone tied (so "top 4" with a 3-way tie at position 4
 *                      yields more than 4 qualifiers — documented behavior).
 *   top_n_per_group  — top N from each group, then cross-group placement:
 *                      group winners take seeds 1..G, runners-up G+1..2G, etc.
 *                      This makes the bracket generator's seed-order pattern
 *                      naturally pair "1A vs 2B / 1B vs 2A".
 *   manual           — skip; host populated downstream participants directly.
 */
class QualificationResolver
{
    /**
     * @return Stage[]  stages that just got participants populated
     */
    public function resolveDownstream(Stage $upstream): array
    {
        $newlyPopulated = [];

        // Defensive: if standings weren't computed (e.g. the upstream's
        // terminal match was Cancelled and the calculator early-exited),
        // every participant's final_position is null. The resolver can't
        // sensibly run top_n / top_n_per_group / all in that state.
        $hasMissingPositions = $upstream->participants()
            ->whereNull('final_position')
            ->exists();
        if ($hasMissingPositions) {
            return [];
        }

        $qualifications = StageQualification::query()
            ->where('source_stage_id', $upstream->id)
            ->get();

        foreach ($qualifications as $q) {
            $target = Stage::find($q->target_stage_id);
            if ($target === null) {
                continue;
            }

            // Idempotency: don't double-populate.
            if ($target->participants()->exists()) {
                continue;
            }

            // Manual: host populates directly; resolver is a no-op.
            if ($q->rule_type === 'manual') {
                continue;
            }

            $populated = match ($q->rule_type) {
                'all'             => $this->populateAll($upstream, $target),
                'top_n'           => $this->populateTopN($upstream, $target, (int) ($q->rule_config['n'] ?? 0)),
                'top_n_per_group' => $this->populateTopNPerGroup($upstream, $target, (int) ($q->rule_config['per_group'] ?? 0)),
                default           => throw new \DomainException(
                    "QualificationResolver: unknown rule_type '{$q->rule_type}' on qualification {$q->id}.",
                ),
            };

            if ($populated > 0 && ! in_array($target, $newlyPopulated, true)) {
                $newlyPopulated[] = $target;
            }
        }

        return $newlyPopulated;
    }

    private function populateAll(Stage $upstream, Stage $target): int
    {
        $participants = $upstream->participants()
            ->orderBy('final_position')
            ->orderBy('seed')
            ->get();

        $seed = 1;
        foreach ($participants as $sp) {
            StageParticipant::create([
                'stage_id'         => $target->id,
                'participant_type' => $sp->participant_type,
                'participant_id'   => $sp->participant_id,
                'seed'             => $seed,
                'status'           => StageParticipantStatus::Active,
            ]);
            $seed++;
        }
        return $participants->count();
    }

    private function populateTopN(Stage $upstream, Stage $target, int $n): int
    {
        if ($n <= 0) {
            return 0;
        }

        $sorted = $upstream->participants()
            ->orderBy('final_position')
            ->orderBy('seed')
            ->get();

        // Inclusive-of-ties at the boundary: take the first N strict, plus
        // anyone tied at the Nth position with the next participant.
        $cutoffPosition = $sorted->get($n - 1)?->final_position;
        if ($cutoffPosition === null) {
            // Fewer than N participants finished — take all of them.
            return $this->insertOrdered($target, $sorted);
        }
        $qualifiers = $sorted->filter(fn ($sp) => $sp->final_position !== null
            && $sp->final_position <= $cutoffPosition,
        )->values();

        return $this->insertOrdered($target, $qualifiers);
    }

    private function populateTopNPerGroup(Stage $upstream, Stage $target, int $perGroup): int
    {
        if ($perGroup <= 0) {
            return 0;
        }

        // Group upstream participants by group_number, take top N from each.
        $byGroup = $upstream->participants()
            ->whereNotNull('group_number')
            ->orderBy('group_number')
            ->orderBy('final_position')
            ->orderBy('seed')
            ->get()
            ->groupBy('group_number');

        if ($byGroup->isEmpty()) {
            return 0;
        }

        // For each "tier" t (0 = group winners, 1 = runners-up, ...), assign
        // seeds across groups: tier 0 gets seeds 1..G, tier 1 gets G+1..2G,
        // etc. This produces the canonical 1A/2B/1B/2A cross-group pattern
        // when the SE generator applies its seed-order to the result.
        $groupCount = $byGroup->count();
        $count      = 0;

        for ($t = 0; $t < $perGroup; $t++) {
            $g = 0;
            foreach ($byGroup as $groupNumber => $participants) {
                $tierMember = $participants->values()->get($t);
                if ($tierMember === null) {
                    $g++;
                    continue;
                }
                $seed = $t * $groupCount + $g + 1;
                StageParticipant::create([
                    'stage_id'         => $target->id,
                    'participant_type' => $tierMember->participant_type,
                    'participant_id'   => $tierMember->participant_id,
                    'seed'             => $seed,
                    'status'           => StageParticipantStatus::Active,
                ]);
                $count++;
                $g++;
            }
        }
        return $count;
    }

    private function insertOrdered(Stage $target, \Illuminate\Support\Collection $participants): int
    {
        $seed = 1;
        foreach ($participants as $sp) {
            StageParticipant::create([
                'stage_id'         => $target->id,
                'participant_type' => $sp->participant_type,
                'participant_id'   => $sp->participant_id,
                'seed'             => $seed,
                'status'           => StageParticipantStatus::Active,
            ]);
            $seed++;
        }
        return $participants->count();
    }
}
