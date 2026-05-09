<?php

namespace App\Services\Advancement;

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\TournamentMatch;

/**
 * Computes the `final_position` field on every `stage_participant` of a
 * just-completed stage, based on the stage's format. Pure read-of-matches
 * → write-of-positions; no side effects beyond the participant updates.
 *
 * Position formulas:
 * - SE: 2^(R - round_of_loss) + 1, where R is the final round. With
 *       a third-place match, semifinal losers split into positions 3 / 4.
 * - DE: walks the L bracket in reverse round order, accumulating
 *       eliminated counts; GF winner = 1, GF loser = 2.
 * - RR: sort by wins desc → game wins desc → seed asc, assign positions
 *       1, 2, 3, ... in order. Multi-group: positions are within-group.
 */
class StandingsCalculator
{
    public function computeFor(Stage $stage): void
    {
        match ($stage->format) {
            'single_elim' => $this->computeSingleElim($stage),
            'double_elim' => $this->computeDoubleElim($stage),
            'round_robin' => $this->computeRoundRobin($stage),
            'swiss'       => throw new \DomainException("StandingsCalculator: swiss not implemented."),
            default       => throw new \DomainException("StandingsCalculator: unknown format '{$stage->format}'."),
        };
    }

    // -----------------------------------------------------------------------
    // Single elimination
    // -----------------------------------------------------------------------

    private function computeSingleElim(Stage $stage): void
    {
        $matches = $stage->matches()->where('bracket_type', BracketType::Winners->value)->get();
        $finalRound = (int) $matches->max('bracket_round');

        // Final at (R, 0). Its winner is position 1, loser is position 2.
        $final = $matches->firstWhere(fn (TournamentMatch $m) =>
            $m->bracket_round === $finalRound && $m->bracket_position === 0,
        );
        if ($final === null || $final->winner_participant_id === null) {
            return;
        }

        $this->setPosition($stage, $final->winner_participant_type, $final->winner_participant_id, 1);
        [$loserType, $loserId] = $this->loserOf($final);
        if ($loserId !== null) {
            $this->setPosition($stage, $loserType, $loserId, 2);
        }

        // Earlier rounds: each round's losers tie at 2^(R - r) + 1.
        // With third-place match: semifinal losers split into 3 and 4.
        for ($r = $finalRound - 1; $r >= 1; $r--) {
            $position = (2 ** ($finalRound - $r)) + 1;
            $roundMatches = $matches->where('bracket_round', $r)->where('bracket_position', '<', $this->matchesInRound($finalRound, $r));

            foreach ($roundMatches as $m) {
                if ($m->winner_participant_id === null) {
                    continue;
                }
                [$lType, $lId] = $this->loserOf($m);
                if ($lId !== null) {
                    $this->setPosition($stage, $lType, $lId, $position);
                }
            }
        }

        // Override semifinal positions if a third-place match is present.
        $thirdPlace = $matches->firstWhere(fn (TournamentMatch $m) =>
            $m->bracket_round === $finalRound && $m->bracket_position === 1,
        );
        if ($thirdPlace !== null && $thirdPlace->winner_participant_id !== null) {
            $this->setPosition($stage, $thirdPlace->winner_participant_type, $thirdPlace->winner_participant_id, 3);
            [$tpLoserType, $tpLoserId] = $this->loserOf($thirdPlace);
            if ($tpLoserId !== null) {
                $this->setPosition($stage, $tpLoserType, $tpLoserId, 4);
            }
        }
    }

    private function matchesInRound(int $finalRound, int $r): int
    {
        return 2 ** ($finalRound - $r);
    }

    // -----------------------------------------------------------------------
    // Double elimination
    // -----------------------------------------------------------------------

    private function computeDoubleElim(Stage $stage): void
    {
        // Determine the actual deciding match: reset if it played, else GF.
        $gf    = $stage->matches()
            ->where('bracket_type', BracketType::GrandFinal->value)
            ->where('bracket_round', 1)
            ->first();
        $reset = $stage->matches()
            ->where('bracket_type', BracketType::GrandFinal->value)
            ->where('bracket_round', 2)
            ->first();

        $deciding = ($reset !== null && $reset->status === MatchStatus::Completed) ? $reset : $gf;
        if ($deciding === null || $deciding->winner_participant_id === null) {
            return;
        }

        $this->setPosition($stage, $deciding->winner_participant_type, $deciding->winner_participant_id, 1);
        [$gfLoserType, $gfLoserId] = $this->loserOf($deciding);
        if ($gfLoserId !== null) {
            $this->setPosition($stage, $gfLoserType, $gfLoserId, 2);
        }

        // Walk L bracket in reverse round order. Each round's losers tie at
        // (eliminated_so_far + 1). Track the running count of teams already
        // eliminated below them in the standings.
        $lMatches = $stage->matches()
            ->where('bracket_type', BracketType::Losers->value)
            ->orderByDesc('bracket_round')
            ->orderBy('bracket_position')
            ->get();

        $eliminatedBelow = 2; // GF loser
        $byRound = $lMatches->groupBy('bracket_round')->sortKeysDesc();

        foreach ($byRound as $round => $roundMatches) {
            $position = $eliminatedBelow + 1;
            foreach ($roundMatches as $m) {
                if ($m->winner_participant_id === null) {
                    continue;
                }
                [$lType, $lId] = $this->loserOf($m);
                if ($lId !== null) {
                    $this->setPosition($stage, $lType, $lId, $position);
                }
            }
            $eliminatedBelow += $roundMatches->count();
        }
    }

    // -----------------------------------------------------------------------
    // Round robin
    // -----------------------------------------------------------------------

    private function computeRoundRobin(Stage $stage): void
    {
        $participants = $stage->participants()->get();
        $matches      = $stage->matches()->get();

        // Group by group_number; null = single group.
        $groups = $participants->groupBy('group_number');

        foreach ($groups as $groupKey => $groupParticipants) {
            $groupMatches = $matches->filter(fn (TournamentMatch $m) => $m->group_number === $groupKey);

            $sorted = $groupParticipants->sort(function (StageParticipant $a, StageParticipant $b) use ($groupMatches) {
                $cmp = $this->wins($b, $groupMatches) <=> $this->wins($a, $groupMatches);
                if ($cmp !== 0) return $cmp;
                $cmp = $this->gameWins($b, $groupMatches) <=> $this->gameWins($a, $groupMatches);
                if ($cmp !== 0) return $cmp;
                return ($a->seed ?? PHP_INT_MAX) <=> ($b->seed ?? PHP_INT_MAX);
            });

            $position = 1;
            foreach ($sorted as $sp) {
                $sp->update(['final_position' => $position]);
                $position++;
            }
        }
    }

    /** Match wins for $sp across $matches. */
    private function wins(StageParticipant $sp, \Illuminate\Support\Collection $matches): int
    {
        return $matches->filter(fn (TournamentMatch $m) =>
            $m->winner_participant_type === $sp->participant_type
            && (int) $m->winner_participant_id === (int) $sp->participant_id,
        )->count();
    }

    /** Game-level win count: sum of $sp's score in matches they won. */
    private function gameWins(StageParticipant $sp, \Illuminate\Support\Collection $matches): int
    {
        $total = 0;
        foreach ($matches as $m) {
            $isParticipantA = $m->participant_a_type === $sp->participant_type
                && (int) $m->participant_a_id === (int) $sp->participant_id;
            $isParticipantB = $m->participant_b_type === $sp->participant_type
                && (int) $m->participant_b_id === (int) $sp->participant_id;
            if (! $isParticipantA && ! $isParticipantB) {
                continue;
            }
            $total += $isParticipantA ? (int) $m->score_a : (int) $m->score_b;
        }
        return $total;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function loserOf(TournamentMatch $match): array
    {
        if ($match->participant_a_id === null || $match->participant_b_id === null) {
            return [null, null];
        }
        if ((int) $match->winner_participant_id === (int) $match->participant_a_id
            && $match->winner_participant_type === $match->participant_a_type) {
            return [$match->participant_b_type, $match->participant_b_id];
        }
        return [$match->participant_a_type, $match->participant_a_id];
    }

    private function setPosition(Stage $stage, string $type, int $id, int $position): void
    {
        $stage->participants()
            ->where('participant_type', $type)
            ->where('participant_id', $id)
            ->update(['final_position' => $position]);
    }
}
