<?php

use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;

function makeApiTournament(int $teams = 8, ?User $creator = null): Tournament
{
    $tournament = Tournament::factory()->state([
        'status'             => TournamentStatus::RegistrationClosed,
        'participant_type'   => 'team',
        'created_by_user_id' => $creator?->id ?? User::factory()->systemManager()->create()->id,
    ])->create();

    $stage = Stage::factory()->for($tournament)->create(['format' => 'single_elim']);
    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);

    for ($i = 1; $i <= $teams; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        TournamentRegistration::factory()->approved()->create([
            'tournament_id'    => $tournament->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
        ]);
    }
    return $tournament->fresh();
}

test('rejects unauthenticated callers', function () {
    $tournament = makeApiTournament();

    $this->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertUnauthorized();
});

test('rejects non-admin callers', function () {
    $tournament = makeApiTournament();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertForbidden();
});

test('admin can build the bracket and the response includes summary + tournament', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = makeApiTournament(8, $admin);

    $r = $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertOk()
        ->assertJsonPath('data.id', $tournament->id)
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('bracket_summary.tournament_id', $tournament->id);

    expect($r->json('bracket_summary.stages.0.matches_generated'))->toBe(7);
    expect($tournament->fresh()->stages()->first()->matches()->count())->toBe(7);
});

test('rejects build when tournament is not in registration_closed', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = makeApiTournament(8, $admin);
    $tournament->update(['status' => TournamentStatus::RegistrationOpen]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertStatus(422);
});

test('rejects rebuild on a tournament that already has matches', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = makeApiTournament(8, $admin);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertOk();

    // Reset tournament status back to RegistrationClosed (manually) so the
    // first precondition passes; the second (matches-already-exist) should now fire.
    $tournament->fresh()->update(['status' => TournamentStatus::RegistrationClosed]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertStatus(422);
});

test('returns 422 when no approved registrations exist for an `all` entry stage', function () {
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

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertStatus(422);
});

test('rejects build when the tournament has no stages', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state([
        'status'             => TournamentStatus::RegistrationClosed,
        'participant_type'   => 'team',
        'created_by_user_id' => $admin->id,
    ])->create();

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertStatus(422);
});
