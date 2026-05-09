<?php

use App\Enums\MatchStatus;
use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;

/**
 * Repro for the demo seeder's exact bracket shape: 4-team SE with
 * third_place_match=true. The third-place match is a separate match in
 * round R (alongside the final, position 1). It feeds from the semifinal
 * losers via loser_advances_to_match_id.
 *
 * The stage is only "complete" when BOTH the final AND the third-place
 * match are terminal — that's by design.
 */

function buildFourTeamSeWithThirdPlace(): array
{
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state([
        'status'             => TournamentStatus::RegistrationClosed,
        'participant_type'   => 'team',
        'created_by_user_id' => $admin->id,
    ])->create();
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'single_elim',
        'config' => ['third_place_match' => true],
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
    }

    return [$admin, $tournament, $stage];
}

test('SE w/ third-place: completing only the final leaves stage and tournament InProgress', function () {
    [$admin, $tournament, $stage] = buildFourTeamSeWithThirdPlace();

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertOk();

    // Round 1 — both semis.
    $semis = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
    foreach ($semis as $m) {
        $this->actingAs($admin)
            ->postJson("/api/matches/{$m->id}/games", [
                'game_number'             => 1,
                'winner_participant_type' => $m->participant_a_type,
                'winner_participant_id'   => $m->participant_a_id,
            ])
            ->assertStatus(201);
    }

    // Round 2 should now be: final (position 0) + third-place (position 1), both Scheduled.
    $r2 = $stage->matches()->where('bracket_round', 2)->orderBy('bracket_position')->get();
    expect($r2)->toHaveCount(2);
    expect($r2[0]->status)->toBe(MatchStatus::Scheduled); // final
    expect($r2[1]->status)->toBe(MatchStatus::Scheduled); // third-place

    // Complete ONLY the final, not the third-place.
    $final = $r2[0];
    $this->actingAs($admin)
        ->postJson("/api/matches/{$final->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => $final->participant_a_type,
            'winner_participant_id'   => $final->participant_a_id,
        ])
        ->assertStatus(201);

    // Stage should still be InProgress because the third-place match is still Scheduled.
    expect($stage->fresh()->status)->toBe(StageStatus::InProgress);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::InProgress);
    expect($r2[1]->fresh()->status)->toBe(MatchStatus::Scheduled);
});

test('SE w/ third-place: completing both the final AND the third-place match flips stage and tournament', function () {
    [$admin, $tournament, $stage] = buildFourTeamSeWithThirdPlace();

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertOk();

    // Round 1.
    foreach ($stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get() as $m) {
        $this->actingAs($admin)
            ->postJson("/api/matches/{$m->id}/games", [
                'game_number'             => 1,
                'winner_participant_type' => $m->participant_a_type,
                'winner_participant_id'   => $m->participant_a_id,
            ])
            ->assertStatus(201);
    }

    // Round 2 — both the final AND the third-place.
    foreach ($stage->matches()->where('bracket_round', 2)->orderBy('bracket_position')->get() as $m) {
        $this->actingAs($admin)
            ->postJson("/api/matches/{$m->id}/games", [
                'game_number'             => 1,
                'winner_participant_type' => $m->participant_a_type,
                'winner_participant_id'   => $m->participant_a_id,
            ])
            ->assertStatus(201);
    }

    expect($stage->fresh()->status)->toBe(StageStatus::Completed);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::Completed);
});
