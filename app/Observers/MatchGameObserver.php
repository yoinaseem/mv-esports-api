<?php

namespace App\Observers;

use App\Enums\MatchStatus;
use App\Models\MatchGame;
use App\Models\TournamentMatch;
use App\Services\Advancement\MatchAdvancementService;
use App\Services\Match\MatchEventLogger;

/**
 * Keeps `matches.score_a` and `matches.score_b` in sync with their
 * children in `match_games`. After recomputing, checks whether the
 * parent match has reached its `best_of` threshold and, if so, auto-
 * completes the match (state machine: Scheduled → InProgress → Completed)
 * and fires the advancement cascade.
 *
 * Auto-completion is forward-only — if a host deletes a previously-
 * winning game such that scores drop below threshold, the match's
 * Completed status is preserved (no auto-revert). That edge requires
 * manager intervention.
 */
class MatchGameObserver
{
    public function __construct(
        private readonly MatchAdvancementService $advancement,
        private readonly MatchEventLogger $logger,
    ) {}

    public function saved(MatchGame $game): void
    {
        $this->recomputeAndMaybeComplete($game);
    }

    public function deleted(MatchGame $game): void
    {
        $this->recomputeAndMaybeComplete($game);
    }

    private function recomputeAndMaybeComplete(MatchGame $game): void
    {
        $match = $game->match;
        if ($match === null) {
            return; // parent was deleted; nothing to update
        }

        $games  = $match->games()->get();
        $scoreA = $games->filter(fn (MatchGame $g) =>
            $g->winner_participant_type === $match->participant_a_type
            && $g->winner_participant_id   === $match->participant_a_id,
        )->count();
        $scoreB = $games->filter(fn (MatchGame $g) =>
            $g->winner_participant_type === $match->participant_b_type
            && $g->winner_participant_id   === $match->participant_b_id,
        )->count();

        // Write scores via query builder so we don't fire a recursive
        // TournamentMatch saved event chain (none currently exists, but
        // defends against a future TournamentMatch observer being added).
        $match->newQuery()
            ->where('id', $match->id)
            ->update(['score_a' => $scoreA, 'score_b' => $scoreB]);

        // Auto-completion: refresh to get the new scores into the in-memory
        // model, then check threshold.
        $match->refresh();
        $this->maybeAutoComplete($match);
    }

    private function maybeAutoComplete(TournamentMatch $match): void
    {
        // Only act in the "play happening" states. Pending (slots empty),
        // Conditional (reset awaiting activation), and the terminal states
        // are all skipped.
        if (! in_array($match->status, [MatchStatus::Scheduled, MatchStatus::InProgress], true)) {
            return;
        }

        // Transition Scheduled → InProgress on the first recorded game.
        // Per the state machine: "scheduled → in_progress: first game starts."
        // The observer fires on match_game saves, so any existing match_game
        // means the match has started.
        if ($match->status === MatchStatus::Scheduled && $match->games()->exists()) {
            $previousStatus = $match->status;
            $match->update(['status' => MatchStatus::InProgress]);
            $this->logger->logStatusChange($match, null, $previousStatus, MatchStatus::InProgress);
            $match->refresh();
        }

        // Auto-complete when one side has a strict majority of game wins.
        // For odd best_of (the supported shape), majority = (best_of+1)/2;
        // intdiv($match->best_of, 2) + 1 produces that without a float. The
        // strict-majority form also defends against any path that sneaks an
        // even best_of through validation: with best_of=2 and a 1-1 score,
        // neither side meets the strict-majority threshold of 2, so auto-
        // completion correctly does NOT fire on the tie.
        $threshold = intdiv($match->best_of, 2) + 1;
        $aWins     = $match->score_a >= $threshold;
        $bWins     = $match->score_b >= $threshold;
        if (! $aWins && ! $bWins) {
            return;
        }

        $winnerType = $aWins ? $match->participant_a_type : $match->participant_b_type;
        $winnerId   = $aWins ? $match->participant_a_id   : $match->participant_b_id;

        $previousStatus = $match->status;
        $match->update([
            'status'                  => MatchStatus::Completed,
            'winner_participant_type' => $winnerType,
            'winner_participant_id'   => $winnerId,
            'completed_at'            => now(),
        ]);
        $this->logger->logStatusChange($match, null, $previousStatus, MatchStatus::Completed);

        // Cascade: FK propagation, stage completion check, tournament completion check.
        // Wrapped in DB::transaction inside the service.
        $this->advancement->advance($match);
    }
}
