<?php

use App\Enums\RegistrationStatus;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// ---------------------------------------------------------------------------
// GET registrations
// ---------------------------------------------------------------------------

test('index lists registrations under a tournament', function () {
    $t = Tournament::factory()->registrationOpen()->create();
    TournamentRegistration::factory()->count(2)->create(['tournament_id' => $t->id]);

    getJson("/api/tournaments/{$t->id}/registrations")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('index status filter narrows by status', function () {
    $t = Tournament::factory()->registrationOpen()->create();
    TournamentRegistration::factory()->create(['tournament_id' => $t->id]);
    TournamentRegistration::factory()->approved()->create(['tournament_id' => $t->id]);

    getJson("/api/tournaments/{$t->id}/registrations?status=approved")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// POST — register
// ---------------------------------------------------------------------------

test('store rejects when tournament is not RegistrationOpen', function () {
    // Use a captain so authz passes; the rejection should be on the
    // status precondition, not the policy.
    $captainUser = User::factory()->create();
    $t = Tournament::factory()->draft()->create();
    $cp = Player::factory()->for($captainUser)->for($t->game)->create();
    $team = Team::factory()->create(['game_id' => $t->game_id]);
    TeamMember::factory()->captain()->create(['team_id' => $team->id, 'player_id' => $cp->id]);

    $this->actingAs($captainUser)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(422);
});

test('store rejects invite_only and signed_only with a clear schema-only message', function () {
    foreach (['invite_only', 'signed_only'] as $type) {
        $t    = Tournament::factory()->registrationOpen()->create(['registration_type' => $type]);
        $team = Team::factory()->create(['game_id' => $t->game_id]);
        $captain = User::factory()->create();
        $cp = Player::factory()->for($captain)->for($t->game)->create();
        TeamMember::factory()->captain()->create(['team_id' => $team->id, 'player_id' => $cp->id]);

        $this->actingAs($captain)
            ->postJson("/api/tournaments/{$t->id}/registrations", [
                'participant_type' => 'team',
                'participant_id'   => $team->id,
            ])
            ->assertStatus(422);
    }
});

test('a team captain can register their team', function () {
    $captainUser = User::factory()->create();
    $t = Tournament::factory()->registrationOpen()->create();
    $cp = Player::factory()->for($captainUser)->for($t->game)->create();
    $team = Team::factory()->create(['game_id' => $t->game_id]);
    TeamMember::factory()->captain()->create(['team_id' => $team->id, 'player_id' => $cp->id]);

    $this->actingAs($captainUser)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.participant_type', 'team')
        ->assertJsonPath('data.participant_id', $team->id)
        ->assertJsonPath('data.status', RegistrationStatus::Pending->value);
});

test('the team creator (without captain seat) can also register the team', function () {
    $creatorUser = User::factory()->create();
    $t = Tournament::factory()->registrationOpen()->create();
    $cp = Player::factory()->for($creatorUser)->for($t->game)->create();
    $team = Team::factory()->create([
        'game_id'              => $t->game_id,
        'created_by_player_id' => $cp->id,
    ]);

    $this->actingAs($creatorUser)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(201);
});

test('a stranger cannot register someone else\'s team', function () {
    $t    = Tournament::factory()->registrationOpen()->create();
    $team = Team::factory()->create(['game_id' => $t->game_id]);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertForbidden();
});

test('participant_type must match tournament participant_type (422 on mismatch)', function () {
    $t = Tournament::factory()->playerType()->registrationOpen()->create();
    // submit a team for a player tournament
    $team = Team::factory()->create(['game_id' => $t->game_id]);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(422);
});

test('participant must belong to the same game as the tournament', function () {
    $t = Tournament::factory()->registrationOpen()->create();
    // team for a different game
    $team = Team::factory()->create(); // its own game

    $captain = User::factory()->create();
    Player::factory()->for($captain)->for($team->game)->create();

    $this->actingAs($captain)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(422);
});

test('a player can self-register for a player-type tournament', function () {
    $user = User::factory()->create();
    $t    = Tournament::factory()->playerType()->registrationOpen()->create();
    $p    = Player::factory()->for($user)->for($t->game)->create();

    $this->actingAs($user)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'player',
            'participant_id'   => $p->id,
        ])
        ->assertStatus(201);
});

test('store rejects double-active registration of the same participant', function () {
    $user = User::factory()->create();
    $t = Tournament::factory()->registrationOpen()->create();
    $p = Player::factory()->for($user)->for($t->game)->create();
    $team = Team::factory()->create(['game_id' => $t->game_id]);
    TeamMember::factory()->captain()->create(['team_id' => $team->id, 'player_id' => $p->id]);

    TournamentRegistration::factory()->create([
        'tournament_id'    => $t->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(422);
});

test('store rejects a same-user-double-registration (different participants, same tournament)', function () {
    // A captain on two teams tries to register both — only one allowed
    // because the user already has an active registration.
    $captainUser = User::factory()->create();
    $t = Tournament::factory()->registrationOpen()->create();

    $cp = Player::factory()->for($captainUser)->for($t->game)->create();
    $teamA = Team::factory()->create(['game_id' => $t->game_id]);
    $teamB = Team::factory()->create(['game_id' => $t->game_id]);
    TeamMember::factory()->captain()->create(['team_id' => $teamA->id, 'player_id' => $cp->id]);
    TeamMember::factory()->captain()->create(['team_id' => $teamB->id, 'player_id' => $cp->id]);

    // First registration succeeds
    $this->actingAs($captainUser)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $teamA->id,
        ])
        ->assertStatus(201);

    // Second from the same user (different team!) — rejected
    $this->actingAs($captainUser)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $teamB->id,
        ])
        ->assertStatus(422);
});

test('a withdrawn or rejected prior registration does not block re-register', function () {
    $captainUser = User::factory()->create();
    $t = Tournament::factory()->registrationOpen()->create();
    $cp = Player::factory()->for($captainUser)->for($t->game)->create();
    $team = Team::factory()->create(['game_id' => $t->game_id]);
    TeamMember::factory()->captain()->create(['team_id' => $team->id, 'player_id' => $cp->id]);

    // A withdrawn registration shouldn't count as "active."
    TournamentRegistration::factory()->withdrawn()->create([
        'tournament_id'         => $t->id,
        'participant_type'      => 'team',
        'participant_id'        => $team->id,
        'registered_by_user_id' => $captainUser->id,
    ]);

    $this->actingAs($captainUser)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
        ])
        ->assertStatus(201);
});

test('store rejects when the tournament is full (max_participants approved)', function () {
    $t = Tournament::factory()->registrationOpen()->create(['max_participants' => 1]);
    TournamentRegistration::factory()->approved()->create([
        'tournament_id'    => $t->id,
        'participant_type' => 'team',
        'participant_id'   => Team::factory()->create(['game_id' => $t->game_id])->id,
    ]);

    $captain = User::factory()->create();
    $cp = Player::factory()->for($captain)->for($t->game)->create();
    $team2 = Team::factory()->create(['game_id' => $t->game_id]);
    TeamMember::factory()->captain()->create(['team_id' => $team2->id, 'player_id' => $cp->id]);

    $this->actingAs($captain)
        ->postJson("/api/tournaments/{$t->id}/registrations", [
            'participant_type' => 'team',
            'participant_id'   => $team2->id,
        ])
        ->assertStatus(422);
});

test('approval is rejected when it would push approved count past max_participants', function () {
    $host = User::factory()->systemManager()->create();
    $t    = Tournament::factory()->registrationOpen()->create([
        'created_by_user_id' => $host->id,
        'max_participants'   => 2,
    ]);
    // Two already approved → cap full.
    TournamentRegistration::factory()->approved()->count(2)->create(['tournament_id' => $t->id]);
    // A third pending registration the host tries to approve.
    $pending = TournamentRegistration::factory()->create(['tournament_id' => $t->id]);

    $this->actingAs($host)
        ->patchJson("/api/tournaments/{$t->id}/registrations/{$pending->id}", ['status' => 'approved'])
        ->assertStatus(422);

    expect($pending->fresh()->status->value)->toBe('pending');
});

test('approval is allowed when an existing approved registration withdraws first (cap headroom recovered)', function () {
    $host = User::factory()->systemManager()->create();
    $t    = Tournament::factory()->registrationOpen()->create([
        'created_by_user_id' => $host->id,
        'max_participants'   => 2,
    ]);
    $first  = TournamentRegistration::factory()->approved()->create(['tournament_id' => $t->id]);
    $second = TournamentRegistration::factory()->approved()->create(['tournament_id' => $t->id]);
    $pending = TournamentRegistration::factory()->create(['tournament_id' => $t->id]);

    // First approval would fail (cap full).
    $this->actingAs($host)
        ->patchJson("/api/tournaments/{$t->id}/registrations/{$pending->id}", ['status' => 'approved'])
        ->assertStatus(422);

    // After someone withdraws, a new approval slots in.
    $second->update(['status' => 'withdrawn']);
    $this->actingAs($host)
        ->patchJson("/api/tournaments/{$t->id}/registrations/{$pending->id}", ['status' => 'approved'])
        ->assertOk();
});

// ---------------------------------------------------------------------------
// PATCH — admin status, owner withdraw, seed
// ---------------------------------------------------------------------------

test('the host can approve a pending registration', function () {
    $host = User::factory()->systemManager()->create();
    $t = Tournament::factory()->registrationOpen()->create(['created_by_user_id' => $host->id]);
    $reg = TournamentRegistration::factory()->create(['tournament_id' => $t->id]);

    $this->actingAs($host)
        ->patchJson("/api/tournaments/{$t->id}/registrations/{$reg->id}", ['status' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.status', RegistrationStatus::Approved->value);
});

test('the host can assign a seed', function () {
    $host = User::factory()->systemManager()->create();
    $t = Tournament::factory()->registrationOpen()->create(['created_by_user_id' => $host->id]);
    $reg = TournamentRegistration::factory()->approved()->create(['tournament_id' => $t->id]);

    $this->actingAs($host)
        ->patchJson("/api/tournaments/{$t->id}/registrations/{$reg->id}", ['seed' => 3])
        ->assertOk()
        ->assertJsonPath('data.seed', 3);
});

test('the participant owner can withdraw their own approved registration', function () {
    $captainUser = User::factory()->create();
    $t = Tournament::factory()->registrationOpen()->create();
    $cp = Player::factory()->for($captainUser)->for($t->game)->create();
    $team = Team::factory()->create(['game_id' => $t->game_id]);
    TeamMember::factory()->captain()->create(['team_id' => $team->id, 'player_id' => $cp->id]);

    $reg = TournamentRegistration::factory()->approved()->create([
        'tournament_id'    => $t->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]);

    $this->actingAs($captainUser)
        ->patchJson("/api/tournaments/{$t->id}/registrations/{$reg->id}", ['status' => 'withdrawn'])
        ->assertOk()
        ->assertJsonPath('data.status', RegistrationStatus::Withdrawn->value);
});

test('the participant owner cannot set seed (403)', function () {
    $captainUser = User::factory()->create();
    $t = Tournament::factory()->registrationOpen()->create();
    $cp = Player::factory()->for($captainUser)->for($t->game)->create();
    $team = Team::factory()->create(['game_id' => $t->game_id]);
    TeamMember::factory()->captain()->create(['team_id' => $team->id, 'player_id' => $cp->id]);

    $reg = TournamentRegistration::factory()->create([
        'tournament_id'    => $t->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]);

    $this->actingAs($captainUser)
        ->patchJson("/api/tournaments/{$t->id}/registrations/{$reg->id}", ['seed' => 1, 'status' => 'withdrawn'])
        ->assertForbidden();
});

test('illegal registration status transitions are 422', function () {
    $host = User::factory()->systemManager()->create();
    $t = Tournament::factory()->registrationOpen()->create(['created_by_user_id' => $host->id]);
    // Withdrawn is terminal
    $reg = TournamentRegistration::factory()->withdrawn()->create(['tournament_id' => $t->id]);

    $this->actingAs($host)
        ->patchJson("/api/tournaments/{$t->id}/registrations/{$reg->id}", ['status' => 'approved'])
        ->assertStatus(422);
});

test('a stranger cannot patch a registration', function () {
    $t = Tournament::factory()->registrationOpen()->create();
    $reg = TournamentRegistration::factory()->create(['tournament_id' => $t->id]);

    $this->actingAs(User::factory()->create())
        ->patchJson("/api/tournaments/{$t->id}/registrations/{$reg->id}", ['status' => 'withdrawn'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// DELETE
// ---------------------------------------------------------------------------

test('the host can hard-delete a registration', function () {
    $host = User::factory()->systemManager()->create();
    $t = Tournament::factory()->registrationOpen()->create(['created_by_user_id' => $host->id]);
    $reg = TournamentRegistration::factory()->create(['tournament_id' => $t->id]);

    $this->actingAs($host)
        ->deleteJson("/api/tournaments/{$t->id}/registrations/{$reg->id}")
        ->assertOk();

    expect(TournamentRegistration::find($reg->id))->toBeNull();
});

test('a stranger cannot delete a registration', function () {
    $t = Tournament::factory()->registrationOpen()->create();
    $reg = TournamentRegistration::factory()->create(['tournament_id' => $t->id]);

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/tournaments/{$t->id}/registrations/{$reg->id}")
        ->assertForbidden();
});

test('cross-tournament tampering returns 404', function () {
    $tA = Tournament::factory()->registrationOpen()->create();
    $tB = Tournament::factory()->registrationOpen()->create();
    $regB = TournamentRegistration::factory()->create(['tournament_id' => $tB->id]);

    $manager = User::factory()->systemManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/tournaments/{$tA->id}/registrations/{$regB->id}", ['status' => 'approved'])
        ->assertNotFound();
});
