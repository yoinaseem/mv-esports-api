<?php

namespace App\Services\Bracket;

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\TournamentMatch;

/**
 * Single-elimination bracket generator.
 *
 * Pads the participant count to the next power of two; top seeds get byes
 * against the missing high-seed slots. Round-1 matches with both slots
 * filled land in `Scheduled`; bye matches land in `Walkover` with the
 * present participant pre-set as the winner. Subsequent rounds are
 * created in `Pending` with empty slots, waiting on the advancement
 * service to populate them.
 *
 * Optional `stage.config.third_place_match` adds one extra match in the
 * final round at bracket_position 1, fed by the two semifinal losers.
 */
class SingleEliminationGenerator implements BracketGenerator
{
    /**
     * Resolve `best_of` for a given round from `stage.config.best_of_per_round`.
     * Lookup tries int + string keys (JSON object keys come back as strings,
     * but tests / config-loaded arrays may use ints). Defaults to 1 when
     * unspecified.
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

        if ($count < 2) {
            throw new \DomainException("single_elim stage {$stage->id} has fewer than 2 participants.");
        }

        $bracketSize = SeedOrderPattern::nextPowerOfTwo($count);
        $rounds      = (int) log($bracketSize, 2);
        $seedOrder   = SeedOrderPattern::forSize($bracketSize);

        $bySeed = $participants->keyBy('seed'); // 1-indexed by seed value

        // Build all rounds skeleton-first so we can wire FKs in a second pass.
        // matchesByRoundPos[round][position] = TournamentMatch
        $matchesByRoundPos = [];
        $byes              = 0;

        // ------------------------------------------------------------------
        // Round 1 — pair seedOrder positions [2k] and [2k+1]
        // ------------------------------------------------------------------
        $r1Count = $bracketSize / 2;
        for ($p = 0; $p < $r1Count; $p++) {
            $seedA = $seedOrder[$p * 2];
            $seedB = $seedOrder[$p * 2 + 1];
            /** @var ?StageParticipant $partA */
            $partA = $bySeed->get($seedA);
            /** @var ?StageParticipant $partB */
            $partB = $bySeed->get($seedB);

            $isBye = $partA === null || $partB === null;
            if ($isBye) {
                $byes++;
            }

            // For a bye, ensure participant_a is the present participant
            // so the walkover semantics ("A won by forfeit") read correctly.
            $present = $partA ?? $partB;

            $row = [
                'stage_id'           => $stage->id,
                'bracket_round'      => 1,
                'bracket_position'   => $p,
                'bracket_type'       => BracketType::Winners,
                'best_of'            => $this->bestOfFor($stage, 1),
            ];

            if ($isBye) {
                $row += [
                    'participant_a_type'      => $present?->participant_type,
                    'participant_a_id'        => $present?->participant_id,
                    'participant_b_type'      => null,
                    'participant_b_id'        => null,
                    'winner_participant_type' => $present?->participant_type,
                    'winner_participant_id'   => $present?->participant_id,
                    'status'                  => MatchStatus::Walkover,
                    'completed_at'            => now(),
                ];
            } else {
                $row += [
                    'participant_a_type' => $partA->participant_type,
                    'participant_a_id'   => $partA->participant_id,
                    'participant_b_type' => $partB->participant_type,
                    'participant_b_id'   => $partB->participant_id,
                    'status'             => MatchStatus::Scheduled,
                ];
            }

            $matchesByRoundPos[1][$p] = TournamentMatch::create($row);
        }

        // ------------------------------------------------------------------
        // Rounds 2..rounds — empty slots, will be populated by advancement.
        // ------------------------------------------------------------------
        for ($r = 2; $r <= $rounds; $r++) {
            $countInRound = $bracketSize / (2 ** $r);
            for ($p = 0; $p < $countInRound; $p++) {
                $matchesByRoundPos[$r][$p] = TournamentMatch::create([
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
        // Optional 3rd-place match — alongside the final.
        // ------------------------------------------------------------------
        $thirdPlace = null;
        if (($stage->config['third_place_match'] ?? false) === true && $rounds >= 2) {
            $thirdPlace = TournamentMatch::create([
                'stage_id'         => $stage->id,
                'bracket_round'    => $rounds,
                'bracket_position' => 1,            // final is at position 0
                'bracket_type'     => BracketType::Winners,
                'best_of'          => $this->bestOfFor($stage, $rounds),
                'status'           => MatchStatus::Pending,
            ]);
        }

        // ------------------------------------------------------------------
        // Wire advancement FKs. winner of (r, p) → (r+1, p/2), slot by parity.
        // ------------------------------------------------------------------
        for ($r = 1; $r < $rounds; $r++) {
            foreach ($matchesByRoundPos[$r] as $p => $match) {
                $next = $matchesByRoundPos[$r + 1][intdiv($p, 2)];
                $match->update([
                    'winner_advances_to_match_id' => $next->id,
                    'winner_advances_to_slot'     => $p % 2 === 0 ? 'a' : 'b',
                ]);
            }
        }

        // Wire semifinal losers → 3rd-place match if configured.
        if ($thirdPlace !== null) {
            $semifinalRound = $rounds - 1;
            foreach ($matchesByRoundPos[$semifinalRound] as $p => $match) {
                $match->update([
                    'loser_advances_to_match_id' => $thirdPlace->id,
                    'loser_advances_to_slot'     => $p === 0 ? 'a' : 'b',
                ]);
            }
        }

        $totalGenerated = collect($matchesByRoundPos)
            ->flatten(1)
            ->count() + ($thirdPlace ? 1 : 0);

        return [
            'matches_generated' => $totalGenerated,
            'byes_assigned'     => $byes,
        ];
    }
}
