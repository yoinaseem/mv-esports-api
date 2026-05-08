<?php

namespace App\Observers;

use App\Models\MatchGame;

/**
 * Keeps `matches.score_a` and `matches.score_b` in sync with their
 * children in `match_games`. Fires on saved (covers both create and
 * update) and deleted, so any path that mutates a match_game row —
 * controller, factory, advancement service, raw save() — will trigger
 * the recompute.
 *
 * Bypassable only by raw SQL writes that skip the model layer entirely.
 * The codebase doesn't have any of those; if it ever does, the bypass
 * is a deliberate choice the writer should reckon with.
 */
class MatchGameObserver
{
    public function saved(MatchGame $game): void
    {
        $this->recomputeMatchScores($game);
    }

    public function deleted(MatchGame $game): void
    {
        $this->recomputeMatchScores($game);
    }

    /**
     * Recompute the parent match's score_a / score_b from its match_games.
     * score_a = count of completed games where the winner equals
     * participant_a; same shape for score_b.
     */
    private function recomputeMatchScores(MatchGame $game): void
    {
        $match = $game->match;
        if ($match === null) {
            return; // parent was deleted; nothing to update
        }

        // Re-read all sibling games to count wins per side.
        $games = $match->games()->get();

        $scoreA = $games->filter(function (MatchGame $g) use ($match) {
            return $g->winner_participant_type === $match->participant_a_type
                && $g->winner_participant_id   === $match->participant_a_id;
        })->count();

        $scoreB = $games->filter(function (MatchGame $g) use ($match) {
            return $g->winner_participant_type === $match->participant_b_type
                && $g->winner_participant_id   === $match->participant_b_id;
        })->count();

        // Avoid recursive observer fires by writing directly via a query
        // builder update. (Using $match->save() would fire Match's saved
        // observer if one were ever added; not the case today, but defends
        // against future entanglement.)
        $match->newQuery()
            ->where('id', $match->id)
            ->update(['score_a' => $scoreA, 'score_b' => $scoreB]);
    }
}
