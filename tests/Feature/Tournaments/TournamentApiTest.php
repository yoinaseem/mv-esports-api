<?php

use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Models\Tournament;
use App\Models\TournamentHost;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/** Helper: a user with the tournaments.create permission via an approved host row. */
function approvedHostUser(): User
{
    $user = User::factory()->create();
    $host = TournamentHost::factory()->approved()->for($user)->create();
    $user->givePermissionTo('tournaments.create');

    return $user->fresh();
}

// ---------------------------------------------------------------------------
// Public reads — drafts hidden by default
// ---------------------------------------------------------------------------

test('index hides drafts and draft-pending-review by default', function () {
    Tournament::factory()->registrationOpen()->create();
    Tournament::factory()->create(); // DraftPendingReview
    Tournament::factory()->draft()->create();

    getJson('/api/tournaments')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a manager can see drafts via include_drafts=1', function () {
    Tournament::factory()->registrationOpen()->create();
    Tournament::factory()->create();
    Tournament::factory()->draft()->create();

    $manager = User::factory()->systemManager()->create();

    $this->actingAs($manager)
        ->getJson('/api/tournaments?include_drafts=1')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('a regular user passing include_drafts=1 still gets only public tournaments', function () {
    Tournament::factory()->registrationOpen()->create();
    Tournament::factory()->draft()->create();

    $this->actingAs(User::factory()->create())
        ->getJson('/api/tournaments?include_drafts=1')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('show hides a draft from anonymous viewers (404)', function () {
    $t = Tournament::factory()->draft()->create();

    getJson("/api/tournaments/{$t->id}")->assertNotFound();
});

test('show reveals a draft to its creator', function () {
    $creator = User::factory()->create();
    $t = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);

    $this->actingAs($creator)
        ->getJson("/api/tournaments/{$t->id}")
        ->assertOk();
});

test('show reveals a draft to a manager', function () {
    $t = Tournament::factory()->draft()->create();

    $this->actingAs(User::factory()->systemManager()->create())
        ->getJson("/api/tournaments/{$t->id}")
        ->assertOk();
});

// ---------------------------------------------------------------------------
// POST /api/tournaments/applications  (host application)
// POST /api/tournaments/drafts         (manager direct)
// ---------------------------------------------------------------------------

test('applications endpoint rejects unauthenticated callers', function () {
    postJson('/api/tournaments/applications', [])->assertUnauthorized();
});

test('drafts endpoint rejects unauthenticated callers', function () {
    postJson('/api/tournaments/drafts', [])->assertUnauthorized();
});

test('applications endpoint rejects users without tournaments.create', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tournaments/applications', [])
        ->assertForbidden();
});

test('drafts endpoint rejects non-managers even with tournaments.create', function () {
    // An approved host has tournaments.create but isn't a manager — they
    // should be redirected to /applications.
    $host = approvedHostUser();
    $game = Game::factory()->create();

    $this->actingAs($host)
        ->postJson('/api/tournaments/drafts', validTournamentPayload($game))
        ->assertForbidden();
});

test('a system_manager creates via /drafts and lands in Draft directly', function () {
    $manager = User::factory()->systemManager()->create();
    $game    = Game::factory()->create();

    $this->actingAs($manager)
        ->postJson('/api/tournaments/drafts', validTournamentPayload($game))
        ->assertStatus(201)
        ->assertJsonPath('data.status', TournamentStatus::Draft->value)
        ->assertJsonPath('data.host_id', null);
});

test('an approved host creates via /applications and lands in DraftPendingReview', function () {
    $host = approvedHostUser();
    $game = Game::factory()->create();

    $this->actingAs($host)
        ->postJson('/api/tournaments/applications', validTournamentPayload($game))
        ->assertStatus(201)
        ->assertJsonPath('data.status', TournamentStatus::DraftPendingReview->value)
        ->assertJsonPath('data.host_id', $host->tournamentHost->id);
});

test('a system_manager can also use /applications (lands in DraftPendingReview, host_id null)', function () {
    // Edge case: a manager can hit /applications. They have the permission.
    // The endpoint always lands in DraftPendingReview; host_id is null
    // because the manager has no tournament_hosts row.
    $manager = User::factory()->systemManager()->create();
    $game    = Game::factory()->create();

    $this->actingAs($manager)
        ->postJson('/api/tournaments/applications', validTournamentPayload($game))
        ->assertStatus(201)
        ->assertJsonPath('data.status', TournamentStatus::DraftPendingReview->value)
        ->assertJsonPath('data.host_id', null);
});

test('drafts endpoint rejects duplicate slugs', function () {
    Tournament::factory()->create(['slug' => 'taken']);
    $manager = User::factory()->systemManager()->create();
    $game    = Game::factory()->create();

    $this->actingAs($manager)
        ->postJson('/api/tournaments/drafts', array_merge(validTournamentPayload($game), ['slug' => 'taken']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);
});

test('a regular user with tournaments.create directly granted (no host row) cannot use /applications (S3)', function () {
    // Direct permission grant without an approved tournament_hosts row —
    // the "application" framing requires actual host status.
    $user = User::factory()->create();
    $user->givePermissionTo('tournaments.create');
    $game = Game::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tournaments/applications', validTournamentPayload($game))
        ->assertForbidden();
});

test('store rejects registration_closes_at after start_date (S2)', function () {
    $manager = User::factory()->systemManager()->create();
    $game    = Game::factory()->create();

    $payload = array_merge(validTournamentPayload($game), [
        'start_date'             => now()->addDays(5)->toDateString(),
        'end_date'               => now()->addDays(7)->toDateString(),
        'registration_closes_at' => now()->addDays(10)->toIso8601String(), // closes AFTER start
    ]);

    $this->actingAs($manager)
        ->postJson('/api/tournaments/drafts', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['registration_closes_at']);
});

// ---------------------------------------------------------------------------
// PATCH non-status fields
// ---------------------------------------------------------------------------

test('PATCH cannot change status — must use verb endpoints', function () {
    $manager = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->draft()->create();

    $this->actingAs($manager)
        ->patchJson("/api/tournaments/{$t->id}", ['status' => 'registration_open'])
        ->assertForbidden();
});

test('the host can patch description', function () {
    $host = approvedHostUser();
    $t = Tournament::factory()->draft()->create([
        'host_id'            => $host->tournamentHost->id,
        'created_by_user_id' => $host->id,
    ]);

    $this->actingAs($host)
        ->patchJson("/api/tournaments/{$t->id}", ['description' => 'New blurb'])
        ->assertOk()
        ->assertJsonPath('data.description', 'New blurb');
});

test('a stranger cannot patch a tournament', function () {
    $t = Tournament::factory()->draft()->create();

    $this->actingAs(User::factory()->create())
        ->patchJson("/api/tournaments/{$t->id}", ['description' => 'Hijacked'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// State-transition verb endpoints
// ---------------------------------------------------------------------------

test('manager can approve a draft-pending-review tournament', function () {
    $manager = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->create(); // DraftPendingReview

    $this->actingAs($manager)
        ->postJson("/api/tournaments/{$t->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', TournamentStatus::Draft->value)
        ->assertJsonPath('data.approved_by_user_id', $manager->id);

    expect($t->fresh()->approved_at)->not->toBeNull();
});

test('approving from any state other than DraftPendingReview is rejected', function () {
    $manager = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->draft()->create();

    $this->actingAs($manager)
        ->postJson("/api/tournaments/{$t->id}/approve")
        ->assertStatus(422);
});

test('a regular user cannot approve a tournament', function () {
    $t = Tournament::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/tournaments/{$t->id}/approve")
        ->assertForbidden();
});

test('manager can reject a draft-pending-review tournament', function () {
    $manager = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->create();

    $this->actingAs($manager)
        ->postJson("/api/tournaments/{$t->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.status', TournamentStatus::Cancelled->value);
});

test('reject from a non-pending state is rejected', function () {
    $manager = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->draft()->create();

    $this->actingAs($manager)
        ->postJson("/api/tournaments/{$t->id}/reject")
        ->assertStatus(422);
});

test('host can open registration on their draft tournament', function () {
    $host = approvedHostUser();
    $t = Tournament::factory()->draft()->create([
        'host_id'            => $host->tournamentHost->id,
        'created_by_user_id' => $host->id,
    ]);

    $this->actingAs($host)
        ->postJson("/api/tournaments/{$t->id}/open-registration")
        ->assertOk()
        ->assertJsonPath('data.status', TournamentStatus::RegistrationOpen->value);
});

test('opening registration from a non-Draft state is rejected', function () {
    $manager = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->create(); // DraftPendingReview

    $this->actingAs($manager)
        ->postJson("/api/tournaments/{$t->id}/open-registration")
        ->assertStatus(422);
});

test('host can close registration', function () {
    $manager = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->registrationOpen()->create();

    $this->actingAs($manager)
        ->postJson("/api/tournaments/{$t->id}/close-registration")
        ->assertOk()
        ->assertJsonPath('data.status', TournamentStatus::RegistrationClosed->value);
});

test('closing registration auto-rejects all pending registrations and leaves approved alone', function () {
    $manager = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->registrationOpen()->create();

    $pending  = \App\Models\TournamentRegistration::factory()->count(3)->create(['tournament_id' => $t->id]);
    $approved = \App\Models\TournamentRegistration::factory()->approved()->create(['tournament_id' => $t->id]);

    // Snapshot updated_at on a pending row before closing — we'll check the
    // mass update bumped it (S1: Eloquent's query-builder update doesn't
    // auto-touch timestamps; we explicitly add updated_at => now()).
    $beforeUpdatedAt = $pending->first()->updated_at;
    sleep(1); // ensure now() lands at a later second

    $this->actingAs($manager)
        ->postJson("/api/tournaments/{$t->id}/close-registration")
        ->assertOk();

    expect($t->registrations()->where('status', \App\Enums\RegistrationStatus::Pending->value)->count())->toBe(0);
    expect($t->registrations()->where('status', \App\Enums\RegistrationStatus::Rejected->value)->count())->toBe(3);
    expect($approved->fresh()->status)->toBe(\App\Enums\RegistrationStatus::Approved); // untouched

    // S1: updated_at was bumped on the auto-rejected rows.
    $afterUpdatedAt = $pending->first()->fresh()->updated_at;
    expect($afterUpdatedAt->greaterThan($beforeUpdatedAt))->toBeTrue();
});

test('cancel works from any non-terminal state', function () {
    $manager = User::factory()->systemManager()->create();

    foreach ([
        TournamentStatus::DraftPendingReview,
        TournamentStatus::Draft,
        TournamentStatus::RegistrationOpen,
        TournamentStatus::RegistrationClosed,
        TournamentStatus::InProgress,
    ] as $status) {
        $t = Tournament::factory()->state(['status' => $status])->create();

        $this->actingAs($manager)
            ->postJson("/api/tournaments/{$t->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', TournamentStatus::Cancelled->value);
    }
});

test('cancelling a terminal tournament is rejected', function () {
    $manager = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->cancelled()->create();

    $this->actingAs($manager)
        ->postJson("/api/tournaments/{$t->id}/cancel")
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// DELETE
// ---------------------------------------------------------------------------

test('creator can soft-delete a draft-pending-review tournament', function () {
    $creator = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->create(['created_by_user_id' => $creator->id]);

    $this->actingAs($creator)
        ->deleteJson("/api/tournaments/{$t->id}")
        ->assertOk();

    expect(Tournament::find($t->id))->toBeNull();
    expect(Tournament::withTrashed()->find($t->id))->not->toBeNull();
});

test('soft-deleting an in-progress tournament is rejected (422)', function () {
    $creator = User::factory()->systemManager()->create();
    $t       = Tournament::factory()->inProgress()->create(['created_by_user_id' => $creator->id]);

    $this->actingAs($creator)
        ->deleteJson("/api/tournaments/{$t->id}")
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** @return array<string, mixed> */
function validTournamentPayload(Game $game): array
{
    return [
        'name'                   => 'Cup of Cups',
        'slug'                   => 'cup-of-cups-'.uniqid(),
        'game_id'                => $game->id,
        'participant_type'       => 'team',
        'registration_type'      => 'open',
        'start_date'             => now()->addDays(10)->toDateString(),
        'end_date'               => now()->addDays(12)->toDateString(),
        'registration_opens_at'  => now()->addDays(1)->toIso8601String(),
        'registration_closes_at' => now()->addDays(9)->toIso8601String(),
        'max_participants'       => 8,
    ];
}
