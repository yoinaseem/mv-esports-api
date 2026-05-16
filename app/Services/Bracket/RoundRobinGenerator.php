<?php

namespace App\Services\Bracket;

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

/**
 * Round-robin generator. Each participant plays every other in their
 * group `legs` times (default 1).
 *
 * Group split: snake distribution by seed (so groups balance in seed
 * strength). Within each group: the classic "circle method" for
 * round-robin scheduling — fix one seat, rotate the rest one position
 * per round, pair across the circle.
 *
 * Multi-leg: the entire (n-1)-round schedule is replayed `legs` times.
 * Leg L's rounds occupy `bracket_round` values `L*(n-1)+1 .. (L+1)*(n-1)`,
 * so leg 1 is rounds 1..n-1, leg 2 is rounds n..2n-2, etc. Pairings
 * repeat in the same order each leg; participant a/b order is preserved
 * across legs (no home/away swap — meaningless for esports and awkward
 * on odd leg counts).
 *
 * Odd group sizes get a phantom participant; rounds where a real
 * participant pairs with the phantom are bye rounds and produce no
 * match row (distinct from SE byes which are real walkover matches).
 *
 * No advancement FKs in round-robin — final standings drive any
 * downstream qualifications, not direct match-to-match advancement.
 */
class RoundRobinGenerator implements BracketGenerator
{
    public function generate(Stage $stage): array
    {
        $participants = $stage->participants()->orderBy('seed')->get();
        $count        = $participants->count();

        if ($count < 2) {
            throw new \DomainException("round_robin stage {$stage->id} has fewer than 2 participants.");
        }

        $groups    = max(1, (int) ($stage->config['groups'] ?? 1));
        $groupSize = (int) ($stage->config['group_size'] ?? $count);

        // Accept under-capacity (snake distribution naturally produces uneven
        // group sizes — e.g., 7 across 2 groups of 4 → 3+4 — and the per-group
        // circle method already pads odd sizes with a phantom). Reject only
        // over-capacity, where there's no seat for the overflow.
        if ($count > $groups * $groupSize) {
            throw new \DomainException(sprintf(
                'round_robin stage %d: %d participants exceed capacity (groups %d × group_size %d = %d).',
                $stage->id,
                $count,
                $groups,
                $groupSize,
                $groups * $groupSize,
            ));
        }

        $totalMatches = 0;
        $bucketed     = $this->snakeDistribute($participants, $groups);
        $bestOf       = (int) ($stage->config['best_of'] ?? 1);
        $legs         = max(1, (int) ($stage->config['legs'] ?? 1));

        foreach ($bucketed as $groupNumber => $groupMembers) {
            $groupNum = $groupNumber + 1; // 1-indexed for the DB

            // Persist group_number on each stage_participant — downstream
            // qualification rules (top_n_per_group) need to know which group
            // a participant belonged to.
            foreach ($groupMembers as $member) {
                $member->update(['group_number' => $groupNum]);
            }

            $totalMatches += $this->generateForGroup($stage, $groupMembers, $groupNum, $bestOf, $legs);
        }

        return [
            'matches_generated' => $totalMatches,
            'byes_assigned'     => 0,
        ];
    }

    /**
     * Snake-distribute $participants across $groups. Seeds flow back and
     * forth across groups: 1→G, then G→1, then 1→G, etc.
     *
     * For 8 participants in 2 groups: G1 = [1, 4, 5, 8], G2 = [2, 3, 6, 7].
     *
     * Algorithm: write the current participant to bucket[$i], then advance
     * $i in the current direction. If $i runs off the end (== $groups) or
     * the start (< 0), CLAMP $i back to the boundary and flip direction.
     * The clamp-then-flip is what produces the doubling at the boundary —
     * the next iteration writes to the same bucket again before the flip
     * carries it backwards.
     *
     * Trace for 8/2: writes go 0,1, then i flips at boundary stays 1, 1,
     * 0, then flips stays 0, 0, 1, then flips stays 1, 1, 0, then flips
     * stays 0. Sequence: G1, G2, G2, G1, G1, G2, G2, G1.
     *
     * @return array<int, array<int, StageParticipant>>  group index → list of participants
     */
    private function snakeDistribute(Collection $participants, int $groups): array
    {
        $bucketed = array_fill(0, $groups, []);
        $i        = 0;
        $forward  = true;

        foreach ($participants as $p) {
            $bucketed[$i][] = $p;

            if ($forward) {
                $i++;
                if ($i === $groups) {
                    $i       = $groups - 1;
                    $forward = false;
                }
            } else {
                $i--;
                if ($i < 0) {
                    $i       = 0;
                    $forward = true;
                }
            }
        }
        return $bucketed;
    }

    /**
     * Run the circle method on $members and persist the resulting matches.
     * Replays the whole (n-1)-round schedule $legs times, with bracket_round
     * offset per leg so each leg occupies a distinct round block.
     * Returns the count of matches written.
     *
     * @param  array<int, StageParticipant>  $members
     */
    private function generateForGroup(Stage $stage, array $members, int $groupNumber, int $bestOf, int $legs): int
    {
        $n = count($members);
        if ($n < 2) {
            return 0;
        }

        // Pad with a phantom (null) for odd sizes.
        $hasPhantom = $n % 2 === 1;
        if ($hasPhantom) {
            $members[] = null; // phantom
            $n++;
        }

        // The circle: index 0 fixed; indices 1..n-1 rotate.
        $rounds  = $n - 1;
        $written = 0;
        $fixed   = $members[0];

        for ($leg = 0; $leg < $legs; $leg++) {
            // Reset rotation at the start of each leg so leg 2 reproduces the
            // same pair sequence as leg 1 (just shifted in bracket_round).
            $rotating = array_slice($members, 1);

            for ($r = 0; $r < $rounds; $r++) {
                $position = 0;
                $pairs    = [];

                // Pair the fixed seat with the last rotating seat (index n-2).
                $pairs[] = [$fixed, $rotating[$n - 2]];

                // Pair the remaining rotating seats (indices 0..n-3) across:
                //   (rot[0], rot[n-3]), (rot[1], rot[n-4]), ...
                for ($i = 0; $i < ($n / 2) - 1; $i++) {
                    $pairs[] = [$rotating[$i], $rotating[$n - 3 - $i]];
                }

                foreach ($pairs as [$a, $b]) {
                    if ($a === null || $b === null) {
                        // Phantom pair — bye round for the real participant.
                        continue;
                    }

                    TournamentMatch::create([
                        'stage_id'           => $stage->id,
                        'bracket_round'      => $leg * $rounds + $r + 1,
                        'bracket_position'   => $position,
                        'bracket_type'       => BracketType::Group,
                        'group_number'       => $groupNumber,
                        'best_of'            => $bestOf,
                        'participant_a_type' => $a->participant_type,
                        'participant_a_id'   => $a->participant_id,
                        'participant_b_type' => $b->participant_type,
                        'participant_b_id'   => $b->participant_id,
                        'status'             => MatchStatus::Scheduled,
                    ]);
                    $position++;
                    $written++;
                }

                // Rotate: move the last rotating seat to the front.
                $last = array_pop($rotating);
                array_unshift($rotating, $last);
            }
        }
        return $written;
    }

    /**
     * Dry-run preview — same algorithm as `generate()` (snake distribute,
     * per-group circle method, phantom byes for odd group sizes, legs
     * replay) but returns a structured array instead of writing match
     * rows.
     *
     * Match count per group is the closed form `C(n, 2) × legs`, derivable
     * without re-running the circle method.
     *
     * @param Collection<int, StageParticipant> $participants
     */
    public function preview(Stage $stage, Collection $participants): array
    {
        $count = $participants->count();

        $groups    = max(1, (int) ($stage->config['groups']     ?? 1));
        $groupSize = (int) ($stage->config['group_size'] ?? $count);
        $legs      = max(1, (int) ($stage->config['legs']      ?? 1));

        if ($count < 2) {
            return [
                'format'         => 'round_robin',
                'buildable'      => false,
                'reason'         => sprintf('round_robin requires at least 2 participants; got %d.', $count),
                'approved_count' => $count,
            ];
        }

        if ($count > $groups * $groupSize) {
            return [
                'format'         => 'round_robin',
                'buildable'      => false,
                'reason'         => sprintf(
                    '%d participants exceed capacity (groups %d × group_size %d = %d).',
                    $count,
                    $groups,
                    $groupSize,
                    $groups * $groupSize,
                ),
                'approved_count' => $count,
            ];
        }

        $ordered  = $participants->sortBy('seed')->values();
        $bucketed = $this->snakeDistribute($ordered, $groups);

        $matchesTotal = 0;
        $groupPayload = [];

        foreach ($bucketed as $idx => $members) {
            $groupNum  = $idx + 1;
            $n         = count($members);
            $hasPhantom = $n % 2 === 1;
            $matchesInGroup = ($n * ($n - 1) / 2) * $legs;
            $matchesTotal  += $matchesInGroup;

            $groupPayload[] = [
                'group_number'     => $groupNum,
                'participants'     => array_map(
                    fn (StageParticipant $sp) => $this->participantSnapshot($sp),
                    $members,
                ),
                'matches_in_group' => (int) $matchesInGroup,
                'has_phantom_bye'  => $hasPhantom,
            ];
        }

        return [
            'format'         => 'round_robin',
            'buildable'      => true,
            'approved_count' => $count,
            'matches_total'  => (int) $matchesTotal,
            'config'         => [
                'groups'      => $groups,
                'group_size'  => $groupSize,
                'legs'        => $legs,
                'best_of'     => (int) ($stage->config['best_of']     ?? 1),
                'allow_draws' => (bool) ($stage->config['allow_draws'] ?? false),
            ],
            'groups'         => $groupPayload,
        ];
    }

    /**
     * Lightweight participant snapshot for preview responses. Pulls
     * registration_id from the transient `_registration_id` attribute
     * set by `EntryPointResolver::compute()`.
     */
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
