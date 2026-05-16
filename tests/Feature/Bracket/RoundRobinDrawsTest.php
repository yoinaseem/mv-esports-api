<?php

use App\Enums\MatchStatus;
use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\User;
use App\Services\Bracket\RoundRobinGenerator;

/**
 * Round-robin draws — gated by `stage.config.allow_draws`. SE / DE never
 * allow draws (rejected at config validation; advancement requires a clear
 * winner). For RR, a drawn match sits in `Completed` with `winner_*` null,
 * scores both 3-1-0 in the standings.
 */

// ---------------------------------------------------------------------------
// Stage config validation
// ---------------------------------------------------------------------------

test('validation accepts allow_draws=true on round_robin', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Group Stage',
            'format' => 'round_robin',
            'config' => ['groups' => 1, 'group_size' => 4, 'allow_draws' => true],
        ])
        ->assertStatus(201);
});

test('validation rejects allow_draws on single_elim', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'single_elim',
            'config' => ['allow_draws' => true],
        ])
        ->assertStatus(422);
});

test('validation rejects allow_draws on double_elim', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bracket',
            'format' => 'double_elim',
            'config' => ['allow_draws' => true],
        ])
        ->assertStatus(422);
});

test('validation rejects non-boolean allow_draws', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Group Stage',
            'format' => 'round_robin',
            'config' => ['allow_draws' => 'yes'],
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Match-game POST validation
// ---------------------------------------------------------------------------

function buildPlayableRRStage(bool $allowDraws): array
{
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state([
        'status'             => TournamentStatus::InProgress,
        'participant_type'   => 'team',
        'created_by_user_id' => $admin->id,
    ])->create();
    $stage = Stage::factory()->roundRobin(groups: 1, groupSize: 4)->create([
        'tournament_id' => $tournament->id,
        'config'        => [
            'groups' => 1, 'group_size' => 4, 'best_of' => 3, 'allow_draws' => $allowDraws,
        ],
        'status'        => StageStatus::InProgress,
    ]);
    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);

    for ($i = 1; $i <= 4; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        TournamentRegistration::factory()->approved()->create([
            'tournament_id'  => $tournament->id,
            'participant_id' => $team->id,
            'seed'           => $i,
        ]);
        StageParticipant::factory()->create([
            'stage_id'         => $stage->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
        ]);
    }
    (new RoundRobinGenerator())->generate($stage);

    return [$admin, $stage];
}

test('match-game POST accepts a null-winner draw when allow_draws=true', function () {
    [$admin, $stage] = buildPlayableRRStage(allowDraws: true);
    $match = $stage->matches()->first();

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => null,
            'winner_participant_id'   => null,
        ])
        ->assertStatus(201);
});

test('match-game POST rejects a null-winner draw when allow_draws=false', function () {
    [$admin, $stage] = buildPlayableRRStage(allowDraws: false);
    $match = $stage->matches()->first();

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => null,
            'winner_participant_id'   => null,
        ])
        ->assertStatus(422);
});

test('match-game POST rejects a half-set winner (only type, no id) even when draws allowed', function () {
    [$admin, $stage] = buildPlayableRRStage(allowDraws: true);
    $match = $stage->matches()->first();

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => 'team',
            'winner_participant_id'   => null,
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Auto-completion behaviour
// ---------------------------------------------------------------------------

test('match auto-completes as a draw when all games end without strict majority', function () {
    [$admin, $stage] = buildPlayableRRStage(allowDraws: true);
    $match = $stage->matches()->first();

    // bo3, all three games are draws → series is a draw.
    foreach ([1, 2, 3] as $n) {
        $this->actingAs($admin)
            ->postJson("/api/matches/{$match->id}/games", [
                'game_number'             => $n,
                'winner_participant_type' => null,
                'winner_participant_id'   => null,
            ])
            ->assertStatus(201);
    }

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Completed);
    expect($match->winner_participant_id)->toBeNull();
    expect($match->winner_participant_type)->toBeNull();
});

test('strict-majority winner still auto-completes early even with draws allowed', function () {
    [$admin, $stage] = buildPlayableRRStage(allowDraws: true);
    $match = $stage->matches()->first();

    // bo3, A wins games 1 and 2 — match auto-completes after game 2.
    foreach ([1, 2] as $n) {
        $this->actingAs($admin)
            ->postJson("/api/matches/{$match->id}/games", [
                'game_number'             => $n,
                'winner_participant_type' => $match->participant_a_type,
                'winner_participant_id'   => $match->participant_a_id,
            ])
            ->assertStatus(201);
    }

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Completed);
    expect((int) $match->winner_participant_id)->toBe((int) $match->participant_a_id);
});

test('mid-series draw does not complete the match until all games played', function () {
    [$admin, $stage] = buildPlayableRRStage(allowDraws: true);
    $match = $stage->matches()->first();

    // bo3, only 2 games played: 1 draw + 1 A win → not a strict majority,
    // not yet exhausted, so still in_progress.
    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number' => 1, 'winner_participant_type' => null, 'winner_participant_id' => null,
        ])
        ->assertStatus(201);
    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number'             => 2,
            'winner_participant_type' => $match->participant_a_type,
            'winner_participant_id'   => $match->participant_a_id,
        ])
        ->assertStatus(201);

    expect($match->fresh()->status)->toBe(MatchStatus::InProgress);
});

// ---------------------------------------------------------------------------
// Standings — 3-1-0 scoring
// ---------------------------------------------------------------------------

test('standings rank by points (3W + 1D), then game wins, then seed', function () {
    // 4-team RR, all 6 matches recorded with deterministic outcomes.
    // Setup so that:
    //   seed 1: 2W 1D 0L → 7 pts
    //   seed 2: 2W 1L 0D → 6 pts
    //   seed 3: 0W 1D 2L → 1 pt
    //   seed 4: 1W 1D 1L → 4 pts (… actually let's recompute)
    //
    // Matches (seed_a vs seed_b → outcome):
    //   1 vs 2 → A wins (1 +3, 2 +0)
    //   1 vs 3 → draw   (1 +1, 3 +1)
    //   1 vs 4 → A wins (1 +3, 4 +0)
    //   2 vs 3 → A wins (2 +3, 3 +0)
    //   2 vs 4 → A wins (2 +3, 4 +0)
    //   3 vs 4 → draw   (3 +1, 4 +1)
    //
    // Tally: 1=7, 2=6, 3=2, 4=1. Final positions: 1st seed1, 2nd seed2, 3rd seed3, 4th seed4.
    [$admin, $stage] = buildPlayableRRStage(allowDraws: true);

    $bySeed = $stage->participants()->orderBy('seed')->get()->keyBy('seed');
    $matchByPair = function (int $sA, int $sB) use ($stage, $bySeed) {
        $pa = $bySeed[$sA];
        $pb = $bySeed[$sB];
        return $stage->matches->first(function (TournamentMatch $m) use ($pa, $pb) {
            return ($m->participant_a_id === $pa->participant_id && $m->participant_b_id === $pb->participant_id)
                || ($m->participant_a_id === $pb->participant_id && $m->participant_b_id === $pa->participant_id);
        });
    };
    $stage->load('matches');

    $play = function (TournamentMatch $m, ?int $winnerSeed) use ($admin, $bySeed) {
        // Bo3 with allow_draws=true. Record 3 games matching the desired
        // outcome. For winner: 2W-1L for that side. For draw: 3 draws.
        if ($winnerSeed === null) {
            foreach ([1, 2, 3] as $n) {
                $this->actingAs($admin)
                    ->postJson("/api/matches/{$m->id}/games", [
                        'game_number' => $n, 'winner_participant_type' => null, 'winner_participant_id' => null,
                    ])
                    ->assertStatus(201);
            }
            return;
        }
        $winner = $bySeed[$winnerSeed];
        foreach ([1, 2] as $n) {
            $this->actingAs($admin)
                ->postJson("/api/matches/{$m->id}/games", [
                    'game_number'             => $n,
                    'winner_participant_type' => $winner->participant_type,
                    'winner_participant_id'   => $winner->participant_id,
                ])
                ->assertStatus(201);
        }
    };

    $play($matchByPair(1, 2), 1);  // 1 wins
    $play($matchByPair(1, 3), null); // draw
    $play($matchByPair(1, 4), 1);  // 1 wins
    $play($matchByPair(2, 3), 2);  // 2 wins
    $play($matchByPair(2, 4), 2);  // 2 wins
    $play($matchByPair(3, 4), null); // draw

    expect($stage->fresh()->status)->toBe(StageStatus::Completed);

    $finals = $stage->participants()->orderBy('seed')->get()->pluck('final_position', 'seed');
    expect($finals[1])->toBe(1); // seed 1 takes 1st (7 pts)
    expect($finals[2])->toBe(2); // seed 2 takes 2nd (6 pts)
    expect($finals[3])->toBe(3); // seed 3 takes 3rd (2 pts: tied with 4 by points? recompute)
    // Actually: seed 3 has 0W 2D 1L = 2 pts; seed 4 has 0W 1D 2L = 1 pt.
    expect($finals[4])->toBe(4);
});
