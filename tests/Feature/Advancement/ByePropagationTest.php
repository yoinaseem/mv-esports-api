<?php

use App\Enums\MatchStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;

test('seed-and-build with a 6-team bracket auto-propagates the 2 byes into round 2', function () {
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

    for ($i = 1; $i <= 6; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        TournamentRegistration::factory()->approved()->create([
            'tournament_id'  => $tournament->id,
            'participant_id' => $team->id,
            'seed'           => $i,
        ]);
    }

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertOk();

    $stage = $stage->fresh();

    // Round-2 matches should have at least one slot filled by a bye-winner.
    $r2 = $stage->matches()->where('bracket_round', 2)->orderBy('bracket_position')->get();
    $r1ByeMatches = $stage->matches()->where('bracket_round', 1)->where('status', MatchStatus::Walkover)->get();

    expect($r1ByeMatches->count())->toBe(2);
    foreach ($r1ByeMatches as $bye) {
        $r2Target = $r2->firstWhere('id', $bye->winner_advances_to_match_id);
        expect($r2Target)->not->toBeNull();
        $slot = $bye->winner_advances_to_slot;
        $idCol = "participant_{$slot}_id";
        expect($r2Target->{$idCol})->toBe($bye->winner_participant_id);
    }
});
