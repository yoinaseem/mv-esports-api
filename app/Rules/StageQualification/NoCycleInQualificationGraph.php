<?php

namespace App\Rules\StageQualification;

use App\Models\StageQualification;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Asserts that adding a qualification rule with source $sourceStageId
 * targeting $targetStageId would not create a cycle in the dependency
 * graph. Walks forward from $targetStageId and rejects if $sourceStageId
 * is reachable. Self-loops (source === target) caught at the same point.
 *
 * source_stage_id may be null (rule pulls from tournament registrations).
 * Null sources are graph leaves and can never close a cycle.
 */
class NoCycleInQualificationGraph implements ValidationRule
{
    public function __construct(
        private readonly ?int $sourceStageId,
        private readonly int $targetStageId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->sourceStageId === null) {
            return; // null source = registrations entry-point, never cyclic
        }

        if ($this->sourceStageId === $this->targetStageId) {
            $fail('A stage cannot qualify into itself.');

            return;
        }

        // BFS forward from target. If we reach source, adding source → target
        // would close the cycle source → target → … → source.
        $reachable = [];
        $queue     = [$this->targetStageId];

        while (! empty($queue)) {
            $current = array_shift($queue);

            if (in_array($current, $reachable, true)) {
                continue;
            }
            $reachable[] = $current;

            // Stages this one feeds into
            $next = StageQualification::query()
                ->where('source_stage_id', $current)
                ->pluck('target_stage_id')
                ->all();

            foreach ($next as $stageId) {
                if (! in_array($stageId, $reachable, true)) {
                    $queue[] = $stageId;
                }
            }
        }

        if (in_array($this->sourceStageId, $reachable, true)) {
            $fail('This qualification rule would create a cycle in the stage dependency graph.');
        }
    }
}
