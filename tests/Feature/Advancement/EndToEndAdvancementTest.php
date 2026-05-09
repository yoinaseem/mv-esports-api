<?php

use App\Enums\MatchGameStatus;
use App\Enums\MatchStatus;
use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\MatchGame;
use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\User;

test('end-to-end: 4-team SE tournament plays through to Completed status', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state([
        'status'             => TournamentStatus::RegistrationClosed,
        'participant_type'   => 'team',
        'created_by_user_id' => $admin->id,
    ])->create();
    $stage = Stage::factory()->for($tournament)->create(['format' => 'single_elim']);
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
    }

    // Build the bracket.
    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertOk();

    expect($tournament->fresh()->status)->toBe(TournamentStatus::InProgress);
    expect($stage->fresh()->status)->toBe(StageStatus::InProgress);

    // Walk round 1 — for each match, post a winning game (best_of=1, so one
    // game completes the match).
    $round1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
    foreach ($round1 as $m) {
        $this->actingAs($admin)
            ->postJson("/api/matches/{$m->id}/games", [
                'game_number'             => 1,
                'winner_participant_type' => $m->participant_a_type,
                'winner_participant_id'   => $m->participant_a_id,
            ])
            ->assertStatus(201);
        expect($m->fresh()->status)->toBe(MatchStatus::Completed);
    }

    // Round 2 (final) should now be Scheduled with both slots populated.
    $final = $stage->matches()->where('bracket_round', 2)->first()->fresh();
    expect($final->status)->toBe(MatchStatus::Scheduled);
    expect($final->participant_a_id)->not->toBeNull();
    expect($final->participant_b_id)->not->toBeNull();

    // Decide the final.
    $this->actingAs($admin)
        ->postJson("/api/matches/{$final->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => $final->participant_a_type,
            'winner_participant_id'   => $final->participant_a_id,
        ])
        ->assertStatus(201);

    expect($final->fresh()->status)->toBe(MatchStatus::Completed);
    expect($stage->fresh()->status)->toBe(StageStatus::Completed);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::Completed);

    // Final positions assigned.
    $positions = $stage->participants()->orderBy('seed')->pluck('final_position')->toArray();
    expect($positions[0])->toBe(1); // top seed wins everything in this play-through
    expect(in_array(2, $positions, true))->toBeTrue();
});

test('end-to-end: multi-stage tournament with RR → SE qualification cascades correctly', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state([
        'status'             => TournamentStatus::RegistrationClosed,
        'participant_type'   => 'team',
        'created_by_user_id' => $admin->id,
    ])->create();

    // Stage 1: 4-team RR (single group)
    $stage1 = Stage::factory()->for($tournament)->create([
        'format' => 'round_robin', 'sort_order' => 0,
        'config' => ['groups' => 1, 'group_size' => 4],
    ]);
    // Stage 2: 2-team SE (top 2 from RR)
    $stage2 = Stage::factory()->for($tournament)->create([
        'format' => 'single_elim', 'sort_order' => 1,
    ]);

    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage1->id,
    ]);
    // Top 2 from group qualifies into stage 2.
    StageQualification::factory()->topNPerGroup(perGroup: 2)->create([
        'source_stage_id' => $stage1->id,
        'target_stage_id' => $stage2->id,
    ]);

    for ($i = 1; $i <= 4; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        TournamentRegistration::factory()->approved()->create([
            'tournament_id'  => $tournament->id,
            'participant_id' => $team->id,
            'seed'           => $i,
        ]);
    }

    // Build entry stage.
    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertOk();

    expect($stage1->fresh()->matches()->count())->toBe(6); // C(4,2)
    expect($stage2->fresh()->matches()->count())->toBe(0); // not built yet

    // Play out RR — top seed (lower seed_number) always wins.
    $bySeed = $stage1->participants()->orderBy('seed')->get()->keyBy('seed');
    foreach ($stage1->matches as $m) {
        $aSeed = $bySeed->search(fn ($sp) => $sp->participant_id === $m->participant_a_id);
        $bSeed = $bySeed->search(fn ($sp) => $sp->participant_id === $m->participant_b_id);
        $winnerSlot = $aSeed < $bSeed ? 'a' : 'b';
        $winnerType = $winnerSlot === 'a' ? $m->participant_a_type : $m->participant_b_type;
        $winnerId   = $winnerSlot === 'a' ? $m->participant_a_id   : $m->participant_b_id;
        $this->actingAs($admin)
            ->postJson("/api/matches/{$m->id}/games", [
                'game_number'             => 1,
                'winner_participant_type' => $winnerType,
                'winner_participant_id'   => $winnerId,
            ])
            ->assertStatus(201);
    }

    // Stage 1 should have completed; stage 2 should now be built.
    expect($stage1->fresh()->status)->toBe(StageStatus::Completed);
    $stage2 = $stage2->fresh();
    expect($stage2->participants()->count())->toBe(2); // top 2
    expect($stage2->matches()->count())->toBe(1);      // 2-team SE
    expect($stage2->status)->toBe(StageStatus::InProgress);

    // Tournament not yet complete.
    expect($tournament->fresh()->status)->toBe(TournamentStatus::InProgress);

    // Decide the stage 2 final.
    $final = $stage2->matches()->first();
    $this->actingAs($admin)
        ->postJson("/api/matches/{$final->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => $final->participant_a_type,
            'winner_participant_id'   => $final->participant_a_id,
        ])
        ->assertStatus(201);

    expect($stage2->fresh()->status)->toBe(StageStatus::Completed);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::Completed);
});
