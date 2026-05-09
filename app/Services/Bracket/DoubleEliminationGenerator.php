<?php

namespace App\Services\Bracket;

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\TournamentMatch;

/**
 * Double-elimination bracket generator.
 *
 * Power-of-two participant counts only (DESIGN.md §10). Builds a winners
 * bracket using the same SE seed-order pattern, then a losers bracket
 * with the standard cross-drop layout, then the grand final and an
 * optional reset match held in `Conditional` until a reset is needed.
 *
 * The losers bracket drop pattern is encoded as a hardcoded lookup per
 * supported bracket size (4 / 8 / 16 / 32). DIAGRAMS.md §6 cites this
 * decision: "use a reference implementation. Don't derive from first
 * principles in a 5-day sprint."
 */
class DoubleEliminationGenerator implements BracketGenerator
{
    /**
     * Resolve `best_of` for a given round from `stage.config.best_of_per_round`.
     * Applied to W and L bracket matches; GrandFinal matches stay at the
     * default of 1 unless the host PATCHes them post-build (the GF is its
     * own phase and conflating it with "round 1 of the W bracket" feels
     * wrong).
     */
    private function bestOfFor(Stage $stage, int $round): int
    {
        $map = $stage->config['best_of_per_round'] ?? [];
        return (int) ($map[$round] ?? $map[(string) $round] ?? 1);
    }

    public function generate(Stage $stage): array
    {
        $participants = $stage->participants()->orderBy('seed')->get();
        $count        = $participants->count();

        if (! SeedOrderPattern::isPowerOfTwo($count) || $count < 4) {
            throw new \DomainException(sprintf(
                'double_elim stage %d requires a power-of-two participant count ≥ 4; got %d.',
                $stage->id,
                $count,
            ));
        }
        if (! in_array($count, [4, 8, 16, 32], true)) {
            throw new \DomainException(sprintf(
                'double_elim stage %d size %d unsupported (drop pattern hardcoded for 4 / 8 / 16 / 32).',
                $stage->id,
                $count,
            ));
        }

        $rounds    = (int) log($count, 2); // winners-bracket round count
        $seedOrder = SeedOrderPattern::forSize($count);
        $bySeed    = $participants->keyBy('seed');
        $reset     = (bool) ($stage->config['grand_final_reset'] ?? false);

        // ------------------------------------------------------------------
        // Winners bracket — same shape as SE, no byes (power-of-two only).
        // ------------------------------------------------------------------
        $w = []; // $w[round][position] = TournamentMatch
        $r1 = $count / 2;
        for ($p = 0; $p < $r1; $p++) {
            $seedA = $seedOrder[$p * 2];
            $seedB = $seedOrder[$p * 2 + 1];
            $partA = $bySeed[$seedA];
            $partB = $bySeed[$seedB];

            $w[1][$p] = TournamentMatch::create([
                'stage_id'           => $stage->id,
                'bracket_round'      => 1,
                'bracket_position'   => $p,
                'bracket_type'       => BracketType::Winners,
                'best_of'            => $this->bestOfFor($stage, 1),
                'participant_a_type' => $partA->participant_type,
                'participant_a_id'   => $partA->participant_id,
                'participant_b_type' => $partB->participant_type,
                'participant_b_id'   => $partB->participant_id,
                'status'             => MatchStatus::Scheduled,
            ]);
        }
        for ($r = 2; $r <= $rounds; $r++) {
            $countInRound = $count / (2 ** $r);
            for ($p = 0; $p < $countInRound; $p++) {
                $w[$r][$p] = TournamentMatch::create([
                    'stage_id'         => $stage->id,
                    'bracket_round'    => $r,
                    'bracket_position' => $p,
                    'bracket_type'     => BracketType::Winners,
                    'best_of'          => $this->bestOfFor($stage, $r),
                    'status'           => MatchStatus::Pending,
                ]);
            }
        }

        // ------------------------------------------------------------------
        // Losers bracket — 2 × (rounds - 1) rounds, drop-pattern by size.
        // ------------------------------------------------------------------
        $lRounds = 2 * ($rounds - 1);
        $l       = [];
        for ($r = 1; $r <= $lRounds; $r++) {
            $countInRound = self::losersCountForRound($count, $r);
            for ($p = 0; $p < $countInRound; $p++) {
                $l[$r][$p] = TournamentMatch::create([
                    'stage_id'         => $stage->id,
                    'bracket_round'    => $r,
                    'bracket_position' => $p,
                    'bracket_type'     => BracketType::Losers,
                    'best_of'          => $this->bestOfFor($stage, $r),
                    'status'           => MatchStatus::Pending,
                ]);
            }
        }

        // ------------------------------------------------------------------
        // Grand final — winners-final winner vs losers-final winner.
        // ------------------------------------------------------------------
        $gf = TournamentMatch::create([
            'stage_id'         => $stage->id,
            'bracket_round'    => 1,
            'bracket_position' => 0,
            'bracket_type'     => BracketType::GrandFinal,
            'best_of'          => 1,
            'status'           => MatchStatus::Pending,
        ]);

        $gfReset = null;
        if ($reset) {
            $gfReset = TournamentMatch::create([
                'stage_id'         => $stage->id,
                'bracket_round'    => 2,
                'bracket_position' => 0,
                'bracket_type'     => BracketType::GrandFinal,
                'best_of'          => 1,
                'status'           => MatchStatus::Conditional,
            ]);
        }

        // ------------------------------------------------------------------
        // Wire winners-bracket advancement FKs.
        //   Winners: w[r][p] winner → w[r+1][p/2] slot a/b.
        //   Winners: w[r][p] loser  → drop into the losers bracket.
        //   Final winners match (w[rounds][0]) winner → gf slot a; loser → l[lRounds][0].
        // ------------------------------------------------------------------
        for ($r = 1; $r < $rounds; $r++) {
            foreach ($w[$r] as $p => $match) {
                $next = $w[$r + 1][intdiv($p, 2)];
                $match->update([
                    'winner_advances_to_match_id' => $next->id,
                    'winner_advances_to_slot'     => $p % 2 === 0 ? 'a' : 'b',
                ]);
            }
        }
        // Winners-final winner → grand final slot a.
        $w[$rounds][0]->update([
            'winner_advances_to_match_id' => $gf->id,
            'winner_advances_to_slot'     => 'a',
        ]);
        // Winners-final loser → losers final.
        $w[$rounds][0]->update([
            'loser_advances_to_match_id' => $l[$lRounds][0]->id,
            'loser_advances_to_slot'     => 'b',
        ]);

        // Drop-pattern: W round k losers (for k < rounds) drop to L bracket.
        $drop = self::dropPattern($count);
        foreach ($drop as $entry) {
            // entry = [w_round, w_pos, l_round, l_pos, l_slot]
            [$wr, $wp, $lr, $lp, $slot] = $entry;
            $w[$wr][$wp]->update([
                'loser_advances_to_match_id' => $l[$lr][$lp]->id,
                'loser_advances_to_slot'     => $slot,
            ]);
        }

        // ------------------------------------------------------------------
        // Wire losers-bracket advancement FKs (winner only — losers go home).
        // ------------------------------------------------------------------
        $loserAdvance = self::losersAdvancement($count);
        foreach ($loserAdvance as $entry) {
            // entry = [l_round_from, l_pos_from, l_round_to, l_pos_to, slot]
            [$rf, $pf, $rt, $pt, $slot] = $entry;
            $l[$rf][$pf]->update([
                'winner_advances_to_match_id' => $l[$rt][$pt]->id,
                'winner_advances_to_slot'     => $slot,
            ]);
        }
        // Losers-final winner → grand final slot b.
        $l[$lRounds][0]->update([
            'winner_advances_to_match_id' => $gf->id,
            'winner_advances_to_slot'     => 'b',
        ]);

        // ------------------------------------------------------------------
        // Grand final → reset match (if configured).
        // ------------------------------------------------------------------
        if ($gfReset !== null) {
            // GF1 → reset match. Slot convention for the reset (when activated):
            //   slot a = W-bracket finalist (the "first" finalist, came from W).
            //   slot b = L-bracket finalist (the "second" finalist, came from L).
            //
            // The activation case is "L wins GF1" — so GF1.winner is the L
            // finalist (→ reset.b) and GF1.loser is the W finalist (→ reset.a).
            // FK propagation alone produces the right slot assignment.
            //
            // For the cancel case (W wins GF1), the reset gets populated with
            // wrong-side data by the same propagation, but the advancement
            // service immediately transitions Conditional → Cancelled and the
            // slot data becomes harmless leftovers.
            $gf->update([
                'winner_advances_to_match_id' => $gfReset->id,
                'winner_advances_to_slot'     => 'b',
                'loser_advances_to_match_id'  => $gfReset->id,
                'loser_advances_to_slot'      => 'a',
            ]);
        }

        // Match counts: W matches = count - 1; L matches = count - 2; +GF (+ optional reset).
        $total = ($count - 1) + ($count - 2) + 1 + ($reset ? 1 : 0);

        return [
            'matches_generated' => $total,
            'byes_assigned'     => 0,
        ];
    }

    /**
     * Count of matches in losers-bracket round $r for a $size-team DE.
     * In standard DE: L round counts cycle as [n/4, n/4, n/8, n/8, ... 1, 1].
     */
    private static function losersCountForRound(int $size, int $r): int
    {
        // For size $size, the losers bracket has 2*(log2($size) - 1) rounds.
        // Round-by-round counts:
        //   round 1: $size/4    (W1 losers paired)
        //   round 2: $size/4    (cross-drop from W2)
        //   round 3: $size/8    (round 1 + 2 winners paired)
        //   round 4: $size/8    (cross-drop from W3)
        //   ...
        //   final two rounds:   1 (loser-bracket SF), 1 (loser-bracket final)
        // The pattern is: floor(($r+1)/2) determines the "tier", and tier $k
        // has count $size / (2 ** ($k + 1)).
        $tier = intdiv($r + 1, 2); // 1, 1, 2, 2, 3, 3, ...
        return max(1, intdiv($size, 2 ** ($tier + 1)));
    }

    /**
     * Hardcoded W → L drop pattern per bracket size. Each entry:
     *   [w_round, w_pos, l_round, l_pos, l_slot]
     *
     * Convention: W1 losers fill L1 (paired in order, no cross). W2..W{rounds-1}
     * losers cross-drop into the next even L round. W round `rounds` (winners
     * final) loser drops to the losers final, handled separately.
     */
    private static function dropPattern(int $size): array
    {
        switch ($size) {
            case 4:
                // W1.0 loser → L1.0 slot a;  W1.1 loser → L1.0 slot b
                return [
                    [1, 0, 1, 0, 'a'],
                    [1, 1, 1, 0, 'b'],
                ];

            case 8:
                // W1 losers → L1 (paired straight)
                // W2 losers → L2 (cross-dropped: W2.0 → L2.1, W2.1 → L2.0)
                return [
                    [1, 0, 1, 0, 'a'],
                    [1, 1, 1, 0, 'b'],
                    [1, 2, 1, 1, 'a'],
                    [1, 3, 1, 1, 'b'],
                    [2, 0, 2, 1, 'b'],   // cross
                    [2, 1, 2, 0, 'b'],   // cross
                ];

            case 16:
                // W1 losers → L1 (paired straight)
                // W2 losers → L2 (cross-dropped, reverse order)
                // W3 losers → L4 (cross-dropped, reverse order)
                $out = [];
                for ($p = 0; $p < 8; $p++) {
                    $out[] = [1, $p, 1, intdiv($p, 2), $p % 2 === 0 ? 'a' : 'b'];
                }
                // W2 → L2: 4 W2 matches, 4 L2 slots, reverse order
                for ($p = 0; $p < 4; $p++) {
                    $out[] = [2, $p, 2, 3 - $p, 'b'];
                }
                // W3 → L4: 2 W3 matches, 2 L4 slots, reverse order
                for ($p = 0; $p < 2; $p++) {
                    $out[] = [3, $p, 4, 1 - $p, 'b'];
                }
                return $out;

            case 32:
                $out = [];
                for ($p = 0; $p < 16; $p++) {
                    $out[] = [1, $p, 1, intdiv($p, 2), $p % 2 === 0 ? 'a' : 'b'];
                }
                for ($p = 0; $p < 8; $p++) {
                    $out[] = [2, $p, 2, 7 - $p, 'b'];
                }
                for ($p = 0; $p < 4; $p++) {
                    $out[] = [3, $p, 4, 3 - $p, 'b'];
                }
                for ($p = 0; $p < 2; $p++) {
                    $out[] = [4, $p, 6, 1 - $p, 'b'];
                }
                return $out;
        }
        throw new \DomainException("dropPattern called with unsupported size {$size}");
    }

    /**
     * Within-losers-bracket advancement. Each entry:
     *   [l_round_from, l_pos_from, l_round_to, l_pos_to, slot]
     *
     * Pattern: round r (odd → "double-up", winners pair into r+1 same position;
     * even → "elimination", winners pair into r+1, halving positions).
     */
    private static function losersAdvancement(int $size): array
    {
        $out     = [];
        $lRounds = 2 * ((int) log($size, 2) - 1);

        for ($r = 1; $r < $lRounds; $r++) {
            $countFrom = self::losersCountForRound($size, $r);
            $countTo   = self::losersCountForRound($size, $r + 1);
            if ($countFrom === $countTo) {
                // Odd→even: feed each into slot a of same-position next-round match
                // (the cross-dropped W loser fills slot b separately).
                for ($p = 0; $p < $countFrom; $p++) {
                    $out[] = [$r, $p, $r + 1, $p, 'a'];
                }
            } else {
                // Even→odd: pair (p, p+1) → (p/2). Standard halving.
                for ($p = 0; $p < $countFrom; $p++) {
                    $out[] = [$r, $p, $r + 1, intdiv($p, 2), $p % 2 === 0 ? 'a' : 'b'];
                }
            }
        }
        return $out;
    }
}
