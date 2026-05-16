<?php

namespace App\Services\Bracket;

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

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
        $bestOf      = (int) ($stage->config['best_of'] ?? 1);

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
                'best_of'            => $bestOf,
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
                    'best_of'          => $bestOf,
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
                'best_of'          => $bestOf,
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

    /**
     * Dry-run preview — mirrors `generate()` round-1 logic without persisting.
     * Shows the bracket size after power-of-2 padding, which seeds receive
     * byes, and the round-1 matchups. Subsequent rounds aren't included in
     * the response (they're empty skeletons until advancement fills them).
     *
     * Total match count: `bracket_size - 1` (single-elim) plus the third-place
     * match if configured.
     *
     * @param Collection<int, StageParticipant> $participants
     */
    public function preview(Stage $stage, Collection $participants): array
    {
        $count = $participants->count();

        if ($count < 2) {
            return [
                'format'         => 'single_elim',
                'buildable'      => false,
                'reason'         => sprintf('single_elim requires at least 2 participants; got %d.', $count),
                'approved_count' => $count,
            ];
        }

        $bracketSize = SeedOrderPattern::nextPowerOfTwo($count);
        $seedOrder   = SeedOrderPattern::forSize($bracketSize);
        $bySeed      = $participants->keyBy(fn (StageParticipant $sp) => (int) $sp->seed);
        $thirdPlace  = ($stage->config['third_place_match'] ?? false) === true && $bracketSize >= 4;

        $round1 = [];
        $byes   = 0;
        $r1Count = $bracketSize / 2;

        for ($p = 0; $p < $r1Count; $p++) {
            $seedA = $seedOrder[$p * 2];
            $seedB = $seedOrder[$p * 2 + 1];
            $partA = $bySeed->get($seedA);
            $partB = $bySeed->get($seedB);

            $isBye = $partA === null || $partB === null;
            if ($isBye) {
                $byes++;
            }

            $round1[] = [
                'position' => $p,
                'kind'     => $isBye ? 'bye' : 'match',
                'a'        => $partA ? $this->participantSnapshot($partA) : null,
                'b'        => $partB ? $this->participantSnapshot($partB) : null,
            ];
        }

        $matchesTotal = $bracketSize - 1 + ($thirdPlace ? 1 : 0);

        return [
            'format'         => 'single_elim',
            'buildable'      => true,
            'approved_count' => $count,
            'bracket_size'   => $bracketSize,
            'byes'           => $byes,
            'matches_total'  => $matchesTotal,
            'config'         => [
                'best_of'           => (int) ($stage->config['best_of']           ?? 1),
                'third_place_match' => $thirdPlace,
            ],
            'round_1'        => $round1,
        ];
    }

    private function participantSnapshot(StageParticipant $sp): array
    {
        $participant = $sp->getRelation('participant') ?? null;

        return [
            'seed'             => (int) $sp->seed,
            'registration_id'  => $sp->getAttribute('_registration_id'),
            'participant_type' => $sp->participant_type,
            'participant_id'   => (int) $sp->participant_id,
            'name'             => $participant?->name ?? $participant?->gamertag ?? null,
        ];
    }
}
