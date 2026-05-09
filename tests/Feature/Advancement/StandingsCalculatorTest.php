<?php

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Models\TournamentMatch;
use App\Services\Advancement\StandingsCalculator;
use App\Services\Bracket\SingleEliminationGenerator;

function buildAndPlaySE(int $teams, bool $thirdPlace = false): Stage
{
    $stage = Stage::factory()->create([
        'config' => $thirdPlace ? ['third_place_match' => true] : null,
    ]);
    for ($i = 1; $i <= $teams; $i++) {
        $team = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id'         => $stage->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
        ]);
    }
    (new SingleEliminationGenerator())->generate($stage);
    return $stage;
}

function decideMatch(TournamentMatch $m, string $winnerSlot): void
{
    $type = $winnerSlot === 'a' ? $m->participant_a_type : $m->participant_b_type;
    $id   = $winnerSlot === 'a' ? $m->participant_a_id   : $m->participant_b_id;
    $m->update([
        'winner_participant_type' => $type,
        'winner_participant_id'   => $id,
        'status'                  => MatchStatus::Completed,
        'completed_at'            => now(),
    ]);
}

test('SE 4-team final positions: 1, 2, 3, 3 (no third-place match)', function () {
    $stage = buildAndPlaySE(4, thirdPlace: false);

    // Decide all matches: top seed wins everything.
    foreach ($stage->matches as $m) {
        if ($m->participant_a_id !== null && $m->participant_b_id === null) continue; // bye, already decided
        if ($m->status === MatchStatus::Walkover) continue;
        // Assume slot a wins (lower seed)
        $aSeed = $stage->participants->firstWhere('participant_id', $m->participant_a_id)?->seed ?? 99;
        $bSeed = $stage->participants->firstWhere('participant_id', $m->participant_b_id)?->seed ?? 99;
        // Manually populate round 2 from round 1
        if ($m->bracket_round === 2 && ($m->participant_a_id === null || $m->participant_b_id === null)) {
            // Round 2 not yet populated by advancement; resolve manually for this test
            $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
            $m->update([
                'participant_a_type' => $r1[0]->winner_participant_type,
                'participant_a_id'   => $r1[0]->winner_participant_id,
                'participant_b_type' => $r1[1]->winner_participant_type,
                'participant_b_id'   => $r1[1]->winner_participant_id,
                'status'             => MatchStatus::Scheduled,
            ]);
            $m->refresh();
            $aSeed = $stage->participants->firstWhere('participant_id', $m->participant_a_id)?->seed ?? 99;
            $bSeed = $stage->participants->firstWhere('participant_id', $m->participant_b_id)?->seed ?? 99;
        }
        decideMatch($m, $aSeed < $bSeed ? 'a' : 'b');
    }

    app(StandingsCalculator::class)->computeFor($stage);

    $bySeed = $stage->participants()->orderBy('seed')->get()->keyBy('seed');
    expect($bySeed[1]->fresh()->final_position)->toBe(1);
    expect($bySeed[2]->fresh()->final_position)->toBe(2);
    expect($bySeed[3]->fresh()->final_position)->toBe(3);
    expect($bySeed[4]->fresh()->final_position)->toBe(3);
});

test('SE 4-team with third-place match: positions 1, 2, 3, 4 (no ties)', function () {
    $stage = buildAndPlaySE(4, thirdPlace: true);

    // Decide R1 — top seeds win.
    $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
    foreach ($r1 as $m) {
        $aSeed = $stage->participants->firstWhere('participant_id', $m->participant_a_id)?->seed ?? 99;
        $bSeed = $stage->participants->firstWhere('participant_id', $m->participant_b_id)?->seed ?? 99;
        decideMatch($m, $aSeed < $bSeed ? 'a' : 'b');
    }

    // Populate R2 final + 3rd-place from R1 results.
    $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
    $final  = $stage->matches()->where('bracket_round', 2)->where('bracket_position', 0)->first();
    $third  = $stage->matches()->where('bracket_round', 2)->where('bracket_position', 1)->first();
    $final->update([
        'participant_a_type' => $r1[0]->winner_participant_type,
        'participant_a_id'   => $r1[0]->winner_participant_id,
        'participant_b_type' => $r1[1]->winner_participant_type,
        'participant_b_id'   => $r1[1]->winner_participant_id,
        'status'             => MatchStatus::Scheduled,
    ]);
    // 3rd-place gets the two semifinal LOSERS.
    $loserA = $r1[0]->participant_a_id === $r1[0]->winner_participant_id ? $r1[0]->participant_b_id : $r1[0]->participant_a_id;
    $loserB = $r1[1]->participant_a_id === $r1[1]->winner_participant_id ? $r1[1]->participant_b_id : $r1[1]->participant_a_id;
    $third->update([
        'participant_a_type' => 'team',
        'participant_a_id'   => $loserA,
        'participant_b_type' => 'team',
        'participant_b_id'   => $loserB,
        'status'             => MatchStatus::Scheduled,
    ]);
    decideMatch($final->fresh(), 'a');
    decideMatch($third->fresh(), 'a');

    app(StandingsCalculator::class)->computeFor($stage);

    $positions = $stage->participants()->pluck('final_position')->sort()->values()->toArray();
    expect($positions)->toBe([1, 2, 3, 4]);
});

test('RR sorts by wins → game wins → seed', function () {
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create();
    $teams = [];
    for ($i = 1; $i <= 4; $i++) {
        $team = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
        $teams[$i] = $team;
        StageParticipant::factory()->create([
            'stage_id'         => $stage->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
        ]);
    }
    $sps = $stage->participants()->orderBy('seed')->get()->keyBy('seed');

    // Construct matches manually: seed 1 beats everyone, seed 2 beats seeds 3 + 4, seed 3 beats seed 4.
    // Final: seed 1 = 3 wins, seed 2 = 2 wins, seed 3 = 1 win, seed 4 = 0 wins.
    $pairs = [
        [1, 2, 1], // seed 1 beats seed 2
        [1, 3, 1], // seed 1 beats seed 3
        [1, 4, 1], // seed 1 beats seed 4
        [2, 3, 2], // seed 2 beats seed 3
        [2, 4, 2], // seed 2 beats seed 4
        [3, 4, 3], // seed 3 beats seed 4
    ];
    $round = 1;
    foreach ($pairs as $i => [$a, $b, $w]) {
        TournamentMatch::factory()->create([
            'stage_id'                => $stage->id,
            'bracket_round'           => $round,
            'bracket_position'        => $i,
            'bracket_type'            => BracketType::Group,
            'group_number'            => 1,
            'participant_a_type'      => 'team',
            'participant_a_id'        => $teams[$a]->id,
            'participant_b_type'      => 'team',
            'participant_b_id'        => $teams[$b]->id,
            'winner_participant_type' => 'team',
            'winner_participant_id'   => $teams[$w]->id,
            'score_a'                 => $w === $a ? 1 : 0,
            'score_b'                 => $w === $b ? 1 : 0,
            'status'                  => MatchStatus::Completed,
        ]);
    }

    app(StandingsCalculator::class)->computeFor($stage);

    expect($sps[1]->fresh()->final_position)->toBe(1);
    expect($sps[2]->fresh()->final_position)->toBe(2);
    expect($sps[3]->fresh()->final_position)->toBe(3);
    expect($sps[4]->fresh()->final_position)->toBe(4);
});

test('RR multi-group computes within-group positions independently', function () {
    $stage = Stage::factory()->roundRobin(groups: 2, groupSize: 2)->create();
    $teams = [];
    for ($i = 1; $i <= 4; $i++) {
        $teams[$i] = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id'         => $stage->id,
            'participant_type' => 'team',
            'participant_id'   => $teams[$i]->id,
            'seed'             => $i,
            'group_number'     => $i <= 2 ? 1 : 2,
        ]);
    }

    // Group 1: seeds 1 vs 2, seed 1 wins
    TournamentMatch::factory()->create([
        'stage_id' => $stage->id, 'bracket_round' => 1, 'bracket_position' => 0,
        'bracket_type' => BracketType::Group, 'group_number' => 1,
        'participant_a_type' => 'team', 'participant_a_id' => $teams[1]->id,
        'participant_b_type' => 'team', 'participant_b_id' => $teams[2]->id,
        'winner_participant_type' => 'team', 'winner_participant_id' => $teams[1]->id,
        'score_a' => 1, 'score_b' => 0,
        'status' => MatchStatus::Completed,
    ]);
    // Group 2: seeds 3 vs 4, seed 3 wins
    TournamentMatch::factory()->create([
        'stage_id' => $stage->id, 'bracket_round' => 1, 'bracket_position' => 0,
        'bracket_type' => BracketType::Group, 'group_number' => 2,
        'participant_a_type' => 'team', 'participant_a_id' => $teams[3]->id,
        'participant_b_type' => 'team', 'participant_b_id' => $teams[4]->id,
        'winner_participant_type' => 'team', 'winner_participant_id' => $teams[3]->id,
        'score_a' => 1, 'score_b' => 0,
        'status' => MatchStatus::Completed,
    ]);

    app(StandingsCalculator::class)->computeFor($stage);

    $sps = $stage->participants()->get()->keyBy('seed');
    expect($sps[1]->fresh()->final_position)->toBe(1); // group 1 winner
    expect($sps[2]->fresh()->final_position)->toBe(2); // group 1 runner-up
    expect($sps[3]->fresh()->final_position)->toBe(1); // group 2 winner
    expect($sps[4]->fresh()->final_position)->toBe(2); // group 2 runner-up
});
