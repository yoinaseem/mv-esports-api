<?php

namespace App\Services\Advancement;

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\TournamentMatch;
use App\Services\Match\MatchEventLogger;
use Illuminate\Support\Facades\DB;

/**
 * Public API for the match-advancement layer. Two entry points:
 *
 *   advance($match)                  — full cascade after a match transitions
 *                                       to a terminal state. Propagates winner /
 *                                       loser, handles GF reset activation /
 *                                       cancellation, checks stage completion,
 *                                       cascades through to tournament completion.
 *                                       Wraps everything in DB::transaction.
 *
 *   propagateTerminalMatchesIn($s)   — single-pass propagation for matches that
 *                                       were created already in a terminal state
 *                                       (byes from the bracket generator). No
 *                                       cascade. Used by SeedAndBuildService.
 */
class MatchAdvancementService
{
    public function __construct(
        private readonly FkPropagator $propagator,
        private readonly StageCompletion $stageCompletion,
        private readonly TournamentCompletion $tournamentCompletion,
        private readonly MatchEventLogger $logger,
    ) {}

    public function advance(TournamentMatch $match): void
    {
        DB::transaction(function () use ($match) {
            // 1. FK propagation (winner → target, loser → target).
            $this->propagator->propagate($match);

            // 2. Special-case grand-final / reset activation.
            if ($match->bracket_type === BracketType::GrandFinal) {
                $this->handleGrandFinalCompletion($match);
            }

            // 3. Stage completion check + cascade.
            $stage = $match->stage()->first();
            if ($stage !== null) {
                $this->stageCompletion->checkAndClose($stage);

                // 4. Tournament completion check.
                $this->tournamentCompletion->checkAndClose($stage->tournament);
            }
        });
    }

    public function propagateTerminalMatchesIn(Stage $stage): void
    {
        $this->propagator->propagateAllTerminalIn($stage);
    }

    /**
     * When the grand final completes:
     *   - If the W-bracket finalist won: cancel the reset (Conditional → Cancelled).
     *   - Otherwise: activate the reset (Conditional → Pending → Scheduled with both
     *     finalists copied into its slots).
     *
     * Identifies "W-bracket finalist" by comparing the GF1 winner's identity
     * with the winners-final winner's identity (both live in the same stage).
     */
    private function handleGrandFinalCompletion(TournamentMatch $gf): void
    {
        // Only act on GF round 1 — the reset itself completing doesn't trigger
        // another cascade beyond standard advancement.
        if ($gf->bracket_round !== 1) {
            return;
        }

        $reset = $gf->stage->matches()
            ->where('bracket_type', BracketType::GrandFinal->value)
            ->where('bracket_round', 2)
            ->first();
        if ($reset === null) {
            return; // no reset configured
        }

        // Find the W-bracket final to identify which finalist came from W.
        $wFinal = $gf->stage->matches()
            ->where('bracket_type', BracketType::Winners->value)
            ->orderByDesc('bracket_round')
            ->first();

        $wWonGf = $wFinal !== null
            && $wFinal->winner_participant_type === $gf->winner_participant_type
            && (int) $wFinal->winner_participant_id === (int) $gf->winner_participant_id;

        if ($wWonGf) {
            // Cancel the reset.
            if ($reset->status === MatchStatus::Conditional) {
                $previousStatus = $reset->status;
                $reset->update(['status' => MatchStatus::Cancelled]);
                $this->logger->logStatusChange($reset, null, $previousStatus, MatchStatus::Cancelled);
            }
            return;
        }

        // L-bracket finalist won the GF: activate the reset. Slots have already
        // been populated by FK propagation (W finalist → slot a per the
        // generator's slot wiring; L finalist → slot b). Just transition status.
        if ($reset->status === MatchStatus::Conditional) {
            $reset->update(['status' => MatchStatus::Pending]);
            $this->logger->logStatusChange($reset, null, MatchStatus::Conditional, MatchStatus::Pending);

            // Both slots are filled by propagation → transition Scheduled.
            $reset->refresh();
            if ($reset->status === MatchStatus::Pending
                && $reset->participant_a_id !== null
                && $reset->participant_b_id !== null) {
                $reset->update(['status' => MatchStatus::Scheduled]);
                $this->logger->logStatusChange($reset, null, MatchStatus::Pending, MatchStatus::Scheduled);
            }
        }
    }

}
