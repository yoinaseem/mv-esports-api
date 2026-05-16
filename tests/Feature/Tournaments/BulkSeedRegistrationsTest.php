<?php

use App\Enums\TournamentStatus;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;

/**
 * Bulk seed assignment for a tournament's approved registrations. Full-set
 * contract: every approved registration must be in the assignments, with
 * seeds forming a contiguous 1..N sequence. Atomic.
 */

function makeAdminTournamentWithApprovals(int $approvedCount, TournamentStatus $status = TournamentStatus::RegistrationClosed): array
{
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state([
        'status'             => $status,
        'participant_type'   => 'team',
        'created_by_user_id' => $admin->id,
    ])->create();

    $regs = [];
    for ($i = 1; $i <= $approvedCount; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        $regs[] = TournamentRegistration::factory()->approved()->create([
            'tournament_id'    => $tournament->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => null,
        ]);
    }

    return [$admin, $tournament, collect($regs)];
}

// ---------------------------------------------------------------------------
// Happy paths
// ---------------------------------------------------------------------------

test('bulk seed full-set with 1..N seeds applies atomically', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(4);

    $assignments = $regs->map(fn ($r, $i) => ['registration_id' => $r->id, 'seed' => $i + 1])->values()->all();

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => $assignments,
        ])
        ->assertStatus(200)
        ->assertJsonCount(4, 'data');

    // All seeds applied.
    foreach ($regs as $i => $r) {
        expect($r->fresh()->seed)->toBe($i + 1);
    }
});

test('bulk seed accepts a shuffled mapping (seed value to a specific registration)', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(4);

    // Reverse: registration[0] gets seed 4, registration[3] gets seed 1, etc.
    $assignments = [
        ['registration_id' => $regs[0]->id, 'seed' => 4],
        ['registration_id' => $regs[1]->id, 'seed' => 3],
        ['registration_id' => $regs[2]->id, 'seed' => 2],
        ['registration_id' => $regs[3]->id, 'seed' => 1],
    ];

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => $assignments,
        ])
        ->assertStatus(200);

    expect($regs[0]->fresh()->seed)->toBe(4);
    expect($regs[3]->fresh()->seed)->toBe(1);
});

test('bulk seed available in RegistrationOpen', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(2, TournamentStatus::RegistrationOpen);

    $assignments = $regs->map(fn ($r, $i) => ['registration_id' => $r->id, 'seed' => $i + 1])->values()->all();

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => $assignments,
        ])
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Validation — per-row
// ---------------------------------------------------------------------------

test('bulk seed rejects assignments missing registration_id', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(2);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => [['seed' => 1], ['seed' => 2]],
        ])
        ->assertStatus(422);
});

test('bulk seed rejects non-positive seeds', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(2);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => [
                ['registration_id' => $regs[0]->id, 'seed' => 0],
                ['registration_id' => $regs[1]->id, 'seed' => 1],
            ],
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Validation — cross-row
// ---------------------------------------------------------------------------

test('bulk seed rejects duplicate seeds', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(3);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => [
                ['registration_id' => $regs[0]->id, 'seed' => 1],
                ['registration_id' => $regs[1]->id, 'seed' => 1],
                ['registration_id' => $regs[2]->id, 'seed' => 3],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Duplicate seed'));
});

test('bulk seed rejects duplicate registration_ids', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(2);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => [
                ['registration_id' => $regs[0]->id, 'seed' => 1],
                ['registration_id' => $regs[0]->id, 'seed' => 2],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Duplicate registration_id'));
});

test('bulk seed rejects non-1..N sequence (gap)', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(3);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => [
                ['registration_id' => $regs[0]->id, 'seed' => 1],
                ['registration_id' => $regs[1]->id, 'seed' => 2],
                ['registration_id' => $regs[2]->id, 'seed' => 5],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, '1..3'));
});

test('bulk seed rejects incomplete assignment (fewer than approved count)', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(4);

    // Send only 3 of 4.
    $assignments = $regs->take(3)->map(fn ($r, $i) => ['registration_id' => $r->id, 'seed' => $i + 1])->values()->all();

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => $assignments,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Full-set'));
});

test('bulk seed rejects extra assignments (more than approved count)', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(2);

    // 3 assignments for 2 approved. Reuse a registration_id to keep the
    // per-row "exists" check off the failing path; cross-row dup will fire.
    // Actually we want to test the full-set count check specifically, so
    // use an unrelated ID for the third entry.
    $other = TournamentRegistration::factory()->approved()->create();

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => [
                ['registration_id' => $regs[0]->id, 'seed' => 1],
                ['registration_id' => $regs[1]->id, 'seed' => 2],
                ['registration_id' => $other->id,  'seed' => 3],
            ],
        ])
        ->assertStatus(422);
});

test('bulk seed rejects registration_id not belonging to the tournament', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(2);
    $foreign = TournamentRegistration::factory()->approved()->create();

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => [
                ['registration_id' => $regs[0]->id, 'seed' => 1],
                ['registration_id' => $foreign->id, 'seed' => 2],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'not an approved registration'));
});

test('bulk seed rejects pending registrations (only approved counted)', function () {
    [$admin, $tournament] = makeAdminTournamentWithApprovals(0, TournamentStatus::RegistrationOpen);

    // Create one approved and one pending in this tournament.
    $team1 = Team::factory()->create(['game_id' => $tournament->game_id]);
    $team2 = Team::factory()->create(['game_id' => $tournament->game_id]);
    $approved = TournamentRegistration::factory()->approved()->create([
        'tournament_id' => $tournament->id, 'participant_id' => $team1->id, 'seed' => null,
    ]);
    $pending = TournamentRegistration::factory()->create([
        'tournament_id' => $tournament->id, 'participant_id' => $team2->id, 'seed' => null,
    ]);

    // Try to seed both — pending should be rejected.
    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => [
                ['registration_id' => $approved->id, 'seed' => 1],
                ['registration_id' => $pending->id,  'seed' => 2],
            ],
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Status preconditions
// ---------------------------------------------------------------------------

test('bulk seed rejects during Draft', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(2, TournamentStatus::Draft);

    $assignments = $regs->map(fn ($r, $i) => ['registration_id' => $r->id, 'seed' => $i + 1])->values()->all();

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => $assignments,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'registration_open or registration_closed'));
});

test('bulk seed rejects during InProgress', function () {
    [$admin, $tournament, $regs] = makeAdminTournamentWithApprovals(2, TournamentStatus::InProgress);

    $assignments = $regs->map(fn ($r, $i) => ['registration_id' => $r->id, 'seed' => $i + 1])->values()->all();

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => $assignments,
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

test('bulk seed rejects unauthenticated caller', function () {
    [$_, $tournament, $regs] = makeAdminTournamentWithApprovals(2);

    $assignments = $regs->map(fn ($r, $i) => ['registration_id' => $r->id, 'seed' => $i + 1])->values()->all();

    $this->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
        'assignments' => $assignments,
    ])->assertStatus(401);
});

test('bulk seed rejects non-admin caller', function () {
    [$_, $tournament, $regs] = makeAdminTournamentWithApprovals(2);
    $stranger = User::factory()->create();

    $assignments = $regs->map(fn ($r, $i) => ['registration_id' => $r->id, 'seed' => $i + 1])->values()->all();

    $this->actingAs($stranger)
        ->patchJson("/api/tournaments/{$tournament->id}/registrations/seeds", [
            'assignments' => $assignments,
        ])
        ->assertStatus(403);
});
