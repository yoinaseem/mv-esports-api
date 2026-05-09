<?php

namespace App\Services\Bracket;

use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the seed-and-build flow:
 *
 *   1. Validate tournament is in RegistrationClosed.
 *   2. Reject if any stage already has matches (no idempotent rebuild).
 *   3. For each stage with an entry-point qualification of rule_type=all,
 *      run the EntryPointResolver to copy approved registrations into the
 *      stage's stage_participants.
 *   4. For each stage with populated participants, dispatch to the
 *      format-specific generator (SE / DE / RR).
 *   5. Transition each just-built stage from Pending → InProgress.
 *   6. Transition the tournament from RegistrationClosed → InProgress.
 *
 * Wrapped in a single DB::transaction. Any exception rolls back everything.
 *
 * Defensive: a runtime invariant check ensures every consumed
 * stage_participant's participant_type matches the parent tournament's
 * participant_type — should never fire if upstream validation worked, but
 * guards against future regressions.
 */
class SeedAndBuildService
{
    public function __construct(
        private readonly EntryPointResolver $resolver,
        private readonly SingleEliminationGenerator $singleElim,
        private readonly DoubleEliminationGenerator $doubleElim,
        private readonly RoundRobinGenerator $roundRobin,
    ) {}

    /**
     * Run the seed-and-build for $tournament. Returns a summary array
     * suitable for the API response.
     *
     * Throws \DomainException with a message on any precondition failure;
     * the controller maps these to 422.
     */
    public function execute(Tournament $tournament): array
    {
        if ($tournament->status !== TournamentStatus::RegistrationClosed) {
            throw new \DomainException(sprintf(
                'Cannot seed-and-build: tournament must be in registration_closed; got %s.',
                $tournament->status->value,
            ));
        }

        $stages = $tournament->stages()->with('incomingQualifications')->get();
        if ($stages->isEmpty()) {
            throw new \DomainException('Cannot seed-and-build: tournament has no stages.');
        }

        // Idempotency check — reject if anything is already built.
        $hasMatches = TournamentMatch::query()
            ->whereIn('stage_id', $stages->pluck('id'))
            ->exists();
        if ($hasMatches) {
            throw new \DomainException('Cannot seed-and-build: matches already exist for one or more stages. Bracket regeneration is not supported.');
        }

        // Validate entry-point qualifications: only 'all' and 'manual' are
        // acceptable when source_stage_id is null.
        foreach ($stages as $stage) {
            foreach ($stage->incomingQualifications as $qual) {
                if ($qual->source_stage_id === null
                    && ! in_array($qual->rule_type, ['all', 'manual'], true)) {
                    throw new \DomainException(sprintf(
                        'Stage %d has a null-source qualification with rule_type=%s; only "all" and "manual" are valid for entry-point qualifications.',
                        $stage->id,
                        $qual->rule_type,
                    ));
                }
            }
        }

        return DB::transaction(function () use ($tournament, $stages) {
            $perStage = [];

            // Step 1: entry-point resolution.
            foreach ($stages as $stage) {
                $hasAllRule = $stage->incomingQualifications->contains(
                    fn ($q) => $q->source_stage_id === null && $q->rule_type === 'all',
                );
                if ($hasAllRule) {
                    $this->resolver->resolve($tournament, $stage);
                }
            }

            // Step 2: validate post-resolution invariants.
            $tournamentType = $tournament->participant_type;
            foreach ($stages as $stage) {
                $stage->load('participants');
                foreach ($stage->participants as $sp) {
                    $this->assertParticipantTypeMatches($sp, $tournamentType);
                }
            }

            // Step 3: generate matches for each populated stage.
            foreach ($stages as $stage) {
                $count = $stage->participants->count();
                if ($count === 0) {
                    // No participants → no generation. The stage stays
                    // Pending and will be activated by a future
                    // qualification-resolver run when its source stage
                    // completes (commit 9).
                    continue;
                }
                if ($count < 2) {
                    throw new \DomainException(sprintf(
                        'Stage %d has %d participant(s); need at least 2 to generate a bracket.',
                        $stage->id,
                        $count,
                    ));
                }

                $generator = match ($stage->format) {
                    'single_elim' => $this->singleElim,
                    'double_elim' => $this->doubleElim,
                    'round_robin' => $this->roundRobin,
                    'swiss'       => throw new \DomainException("Stage {$stage->id} uses swiss format, which is not implemented yet."),
                    default       => throw new \DomainException("Stage {$stage->id} has unknown format '{$stage->format}'."),
                };

                $summary = $generator->generate($stage);
                $perStage[] = [
                    'stage_id'          => $stage->id,
                    'format'            => $stage->format,
                    'matches_generated' => $summary['matches_generated'],
                    'byes_assigned'     => $summary['byes_assigned'],
                ];

                // Transition stage Pending → InProgress.
                if (! $stage->status->canTransitionTo(StageStatus::InProgress)) {
                    throw new \DomainException(sprintf(
                        'Stage %d cannot transition from %s to in_progress.',
                        $stage->id,
                        $stage->status->value,
                    ));
                }
                $stage->update(['status' => StageStatus::InProgress]);
            }

            if (empty($perStage)) {
                throw new \DomainException('No stages were built — the entry stage has no participants. Did the resolver find any approved registrations?');
            }

            // Step 4: tournament transition.
            if (! $tournament->status->canTransitionTo(TournamentStatus::InProgress)) {
                throw new \DomainException(sprintf(
                    'Tournament cannot transition from %s to in_progress.',
                    $tournament->status->value,
                ));
            }
            $tournament->update(['status' => TournamentStatus::InProgress]);

            return [
                'tournament_id' => $tournament->id,
                'stages'        => $perStage,
            ];
        });
    }

    /**
     * Defensive — every consumed stage_participant must agree with the
     * tournament's participant_type. Fires inside the transaction so a
     * violation rolls back cleanly.
     */
    private function assertParticipantTypeMatches(StageParticipant $sp, string $tournamentType): void
    {
        if ($sp->participant_type !== $tournamentType) {
            throw new \DomainException(sprintf(
                'StageParticipant %d has type %s but tournament participant_type is %s.',
                $sp->id,
                $sp->participant_type,
                $tournamentType,
            ));
        }
    }
}
