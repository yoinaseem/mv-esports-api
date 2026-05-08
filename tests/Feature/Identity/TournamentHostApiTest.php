<?php

use App\Models\TournamentHost;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// ---------------------------------------------------------------------------
// GET /api/tournament-hosts
// ---------------------------------------------------------------------------

test('index lists hosts and supports a status filter', function () {
    TournamentHost::factory()->count(2)->create();
    TournamentHost::factory()->approved()->create();

    getJson('/api/tournament-hosts')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    getJson('/api/tournament-hosts?status=pending')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    getJson('/api/tournament-hosts?status=approved')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('show returns the requested host record', function () {
    $host = TournamentHost::factory()->create();

    getJson("/api/tournament-hosts/{$host->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $host->id);
});

// ---------------------------------------------------------------------------
// POST /api/tournament-hosts (apply)
// ---------------------------------------------------------------------------

test('an authenticated user can apply to be a tournament host (status=pending)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tournament-hosts', [
            'display_name' => 'Some Host',
            'bio'          => 'Running events for 5 years.',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.approved_by_user_id', null);
});

test('apply rejects a second application from the same user', function () {
    $user = User::factory()->create();
    TournamentHost::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson('/api/tournament-hosts', ['display_name' => 'Dup'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// PATCH — bio/display_name (owner) vs status (manager)
// ---------------------------------------------------------------------------

test('the host themselves can patch display_name and bio', function () {
    $host = TournamentHost::factory()->create();

    $this->actingAs($host->user)
        ->patchJson("/api/tournament-hosts/{$host->id}", [
            'display_name' => 'Updated Name',
            'bio'          => 'Refreshed bio.',
        ])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Updated Name')
        ->assertJsonPath('data.bio', 'Refreshed bio.');
});

test('the host themselves cannot change their own status (403)', function () {
    $host = TournamentHost::factory()->create();

    $this->actingAs($host->user)
        ->patchJson("/api/tournament-hosts/{$host->id}", ['status' => 'approved'])
        ->assertForbidden();

    expect($host->fresh()->status)->toBe('pending');
});

test('a stranger cannot patch a host record at all', function () {
    $host     = TournamentHost::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patchJson("/api/tournament-hosts/{$host->id}", ['bio' => 'Hijacked'])
        ->assertForbidden();
});

test('a system_manager can approve a pending host (sets approver + approved_at)', function () {
    $manager = User::factory()->systemManager()->create();
    $host    = TournamentHost::factory()->create();

    $this->actingAs($manager)
        ->patchJson("/api/tournament-hosts/{$host->id}", ['status' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_by_user_id', $manager->id);

    expect($host->fresh()->approved_at)->not->toBeNull();
});

test('a system_manager can suspend an approved host', function () {
    $manager = User::factory()->systemManager()->create();
    $host    = TournamentHost::factory()->approved()->create();

    $this->actingAs($manager)
        ->patchJson("/api/tournament-hosts/{$host->id}", ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');
});

test('an unknown status value is a 422', function () {
    $manager = User::factory()->systemManager()->create();
    $host    = TournamentHost::factory()->create();

    $this->actingAs($manager)
        ->patchJson("/api/tournament-hosts/{$host->id}", ['status' => 'banned'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

// ---------------------------------------------------------------------------
// DELETE
// ---------------------------------------------------------------------------

test('the host themselves can withdraw (delete) their record', function () {
    $host = TournamentHost::factory()->create();

    $this->actingAs($host->user)
        ->deleteJson("/api/tournament-hosts/{$host->id}")
        ->assertOk();

    expect(TournamentHost::find($host->id))->toBeNull();
});

test('a system_manager can delete any host record', function () {
    $manager = User::factory()->systemManager()->create();
    $host    = TournamentHost::factory()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/tournament-hosts/{$host->id}")
        ->assertOk();
});

test('a stranger cannot delete a host record', function () {
    $host     = TournamentHost::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->deleteJson("/api/tournament-hosts/{$host->id}")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Capability gate: tournaments.create permission lifecycle
// ---------------------------------------------------------------------------

test('superadmin and system_manager have tournaments.create through their roles by default', function () {
    $superadmin = User::factory()->superadmin()->create();
    $manager    = User::factory()->systemManager()->create();
    $regular    = User::factory()->create();

    expect($superadmin->can('tournaments.create'))->toBeTrue();
    expect($manager->can('tournaments.create'))->toBeTrue();
    expect($regular->can('tournaments.create'))->toBeFalse();
});

test('approving a host grants tournaments.create directly to that user', function () {
    $manager = User::factory()->systemManager()->create();
    $host    = TournamentHost::factory()->create();
    $hostUser = $host->user;

    expect($hostUser->can('tournaments.create'))->toBeFalse();

    $this->actingAs($manager)
        ->patchJson("/api/tournament-hosts/{$host->id}", ['status' => 'approved'])
        ->assertOk();

    expect($hostUser->fresh()->can('tournaments.create'))->toBeTrue();
});

test('suspending an approved host revokes tournaments.create from that user', function () {
    $manager  = User::factory()->systemManager()->create();
    $host     = TournamentHost::factory()->approved()->create();
    $hostUser = $host->user;
    // Simulate the prior approval grant that the auto-sync would have done.
    $hostUser->givePermissionTo('tournaments.create');
    expect($hostUser->fresh()->can('tournaments.create'))->toBeTrue();

    $this->actingAs($manager)
        ->patchJson("/api/tournament-hosts/{$host->id}", ['status' => 'suspended'])
        ->assertOk();

    expect($hostUser->fresh()->can('tournaments.create'))->toBeFalse();
});

test('deleting a host record revokes tournaments.create from that user', function () {
    $manager  = User::factory()->systemManager()->create();
    $host     = TournamentHost::factory()->approved()->create();
    $hostUser = $host->user;
    $hostUser->givePermissionTo('tournaments.create');
    expect($hostUser->fresh()->can('tournaments.create'))->toBeTrue();

    $this->actingAs($manager)
        ->deleteJson("/api/tournament-hosts/{$host->id}")
        ->assertOk();

    expect($hostUser->fresh()->can('tournaments.create'))->toBeFalse();
});

test('a system_manager who is also a host keeps tournaments.create through their role even if their own host record is suspended', function () {
    // Edge case: the manager themselves applies for host status (allowed,
    // since system_manager and tournament_host are independent layers).
    // If their host row is later suspended, role-derived perms must persist.
    $managerHost = User::factory()->systemManager()->create();
    $managerHost->givePermissionTo('tournaments.create'); // simulate prior direct grant
    $host = TournamentHost::factory()->approved()->for($managerHost)->create();

    $otherManager = User::factory()->systemManager()->create();

    $this->actingAs($otherManager)
        ->patchJson("/api/tournament-hosts/{$host->id}", ['status' => 'suspended'])
        ->assertOk();

    // Direct grant gone; role-derived grant persists.
    expect($managerHost->fresh()->can('tournaments.create'))->toBeTrue();
    expect($managerHost->fresh()->getDirectPermissions()->pluck('name'))->not->toContain('tournaments.create');
});

test('reapproving a previously-suspended host re-grants tournaments.create', function () {
    $manager = User::factory()->systemManager()->create();
    $host    = TournamentHost::factory()->suspended()->create();
    expect($host->user->fresh()->can('tournaments.create'))->toBeFalse();

    $this->actingAs($manager)
        ->patchJson("/api/tournament-hosts/{$host->id}", ['status' => 'approved'])
        ->assertOk();

    expect($host->user->fresh()->can('tournaments.create'))->toBeTrue();
});
