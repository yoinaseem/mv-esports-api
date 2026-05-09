<?php

use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Player;
use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;

/**
 * Lifecycle timestamps and the registration enforcement window.
 *
 * - `started_at` stamps the actual moment the tournament went live (status
 *   flipped to InProgress via seed-and-build).
 * - `completed_at` stamps the actual moment the tournament ended (status
 *   flipped to Completed via the advancement service when the final stage's
 *   final match completed).
 * - `start_date` / `end_date` stay as the host's intent — mutable on PATCH,
 *   no auto-extension from match schedules.
 * - `registration_opens_at` / `registration_closes_at` define the
 *   enforcement window. Registration POST rejects outside the window.
 */

function buildPlayableTournament(): array
{
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

    return [$admin, $tournament, $stage];
}

// ---------------------------------------------------------------------------
// started_at / completed_at
// ---------------------------------------------------------------------------

test('started_at is null until the tournament transitions to InProgress', function () {
    $tournament = Tournament::factory()->state(['status' => TournamentStatus::Draft])->create();
    expect($tournament->started_at)->toBeNull();

    $tournament->update(['status' => TournamentStatus::RegistrationOpen]);
    expect($tournament->fresh()->started_at)->toBeNull();
});

test('started_at is set when seed-and-build flips the tournament to InProgress', function () {
    [$admin, $tournament] = buildPlayableTournament();
    expect($tournament->started_at)->toBeNull();

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/seed-and-build")
        ->assertOk();

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::InProgress);
    expect($tournament->started_at)->not->toBeNull();
    expect($tournament->started_at->isToday())->toBeTrue();
});

test('completed_at is null until the tournament transitions to Completed', function () {
    [$admin, $tournament, $stage] = buildPlayableTournament();
    $this->actingAs($admin)->postJson("/api/tournaments/{$tournament->id}/seed-and-build")->assertOk();

    expect($tournament->fresh()->completed_at)->toBeNull();
});

test('completed_at is set when the final match completes and the tournament cascades to Completed', function () {
    [$admin, $tournament, $stage] = buildPlayableTournament();
    $this->actingAs($admin)->postJson("/api/tournaments/{$tournament->id}/seed-and-build")->assertOk();

    foreach ($stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get() as $m) {
        $this->actingAs($admin)
            ->postJson("/api/matches/{$m->id}/games", [
                'game_number'             => 1,
                'winner_participant_type' => $m->participant_a_type,
                'winner_participant_id'   => $m->participant_a_id,
            ])
            ->assertStatus(201);
    }

    $final = $stage->matches()->where('bracket_round', 2)->first()->fresh();
    $this->actingAs($admin)
        ->postJson("/api/matches/{$final->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => $final->participant_a_type,
            'winner_participant_id'   => $final->participant_a_id,
        ])
        ->assertStatus(201);

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::Completed);
    expect($tournament->completed_at)->not->toBeNull();
    expect($tournament->completed_at->isToday())->toBeTrue();
});

// ---------------------------------------------------------------------------
// PATCH date mutability
// ---------------------------------------------------------------------------

test('PATCH /tournaments/{id} updates start_date / end_date / registration window', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}", [
            'start_date'             => '2026-07-01',
            'end_date'               => '2026-07-05',
            'registration_opens_at'  => '2026-06-15T00:00:00Z',
            'registration_closes_at' => '2026-06-30T23:59:59Z',
        ])
        ->assertOk();

    $tournament->refresh();
    expect($tournament->start_date->toDateString())->toBe('2026-07-01');
    expect($tournament->end_date->toDateString())->toBe('2026-07-05');
    expect($tournament->registration_opens_at->toDateString())->toBe('2026-06-15');
    expect($tournament->registration_closes_at->toDateString())->toBe('2026-06-30');
});

// ---------------------------------------------------------------------------
// Registration window enforcement
// ---------------------------------------------------------------------------

function makeOpenTournamentWithCaptain(array $overrides): array
{
    $tournament = Tournament::factory()->registrationOpen()->create(array_merge([
        'participant_type' => 'team',
    ], $overrides));

    $captain = User::factory()->create();
    $cp      = Player::factory()->for($captain)->for($tournament->game)->create();
    $team    = Team::factory()->create(['game_id' => $tournament->game_id]);
    TeamMember::factory()->captain()->create(['team_id' => $team->id, 'player_id' => $cp->id]);

    return [$tournament, $captain, $team];
}

test('registration is rejected before registration_opens_at', function () {
    [$tournament, $captain, $team] = makeOpenTournamentWithCaptain([
        'registration_opens_at'  => now()->addDays(2),
        'registration_closes_at' => now()->addDays(7),
    ]);

    $this->actingAs($captain)
        ->postJson("/api/tournaments/{$tournament->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Registration is not currently within its open window.');
});

test('registration is rejected after registration_closes_at', function () {
    [$tournament, $captain, $team] = makeOpenTournamentWithCaptain([
        'registration_opens_at'  => now()->subDays(7),
        'registration_closes_at' => now()->subDays(1),
    ]);

    $this->actingAs($captain)
        ->postJson("/api/tournaments/{$tournament->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Registration is not currently within its open window.');
});

test('registration is accepted inside the window', function () {
    [$tournament, $captain, $team] = makeOpenTournamentWithCaptain([
        'registration_opens_at'  => now()->subDays(1),
        'registration_closes_at' => now()->addDays(5),
    ]);

    $this->actingAs($captain)
        ->postJson("/api/tournaments/{$tournament->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(201);
});
