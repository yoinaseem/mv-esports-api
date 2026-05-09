<?php

use App\Models\Organization;
use App\Models\TournamentHost;
use App\Models\User;

use function Pest\Laravel\getJson;

test('rejects unauthenticated callers', function () {
    getJson('/api/auth/me/host-status')->assertUnauthorized();
});

test('returns has_application=false for users without a host row', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/auth/me/host-status')
        ->assertOk()
        ->assertJson([
            'has_application' => false,
            'status'          => 'none',
            'host_id'         => null,
            'organization_id' => null,
            'display_name'    => null,
            'applied_at'      => null,
            'approved_at'     => null,
        ]);
});

test('returns the pending host row for users with an application', function () {
    $user = User::factory()->create();
    $host = TournamentHost::factory()->create([
        'user_id'      => $user->id,
        'display_name' => 'host-bob',
        'status'       => 'pending',
    ]);

    $this->actingAs($user)
        ->getJson('/api/auth/me/host-status')
        ->assertOk()
        ->assertJson([
            'has_application' => true,
            'status'          => 'pending',
            'host_id'         => $host->id,
            'display_name'    => 'host-bob',
            'approved_at'     => null,
        ]);
});

test('returns approved host with approved_at populated', function () {
    $user = User::factory()->create();
    $approver = User::factory()->systemManager()->create();
    $host = TournamentHost::factory()->create([
        'user_id'             => $user->id,
        'display_name'        => 'host-carla',
        'status'              => 'approved',
        'approved_by_user_id' => $approver->id,
        'approved_at'         => now(),
    ]);

    $r = $this->actingAs($user)
        ->getJson('/api/auth/me/host-status')
        ->assertOk()
        ->assertJsonPath('has_application', true)
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('host_id', $host->id);

    expect($r->json('approved_at'))->not->toBeNull();
});

test('returns suspended status for suspended host', function () {
    $user = User::factory()->create();
    TournamentHost::factory()->create([
        'user_id' => $user->id,
        'status'  => 'suspended',
    ]);

    $this->actingAs($user)
        ->getJson('/api/auth/me/host-status')
        ->assertOk()
        ->assertJsonPath('status', 'suspended')
        ->assertJsonPath('has_application', true);
});

test('includes organization_id when host is affiliated with one', function () {
    $user = User::factory()->create();
    $org  = Organization::factory()->create();
    TournamentHost::factory()->create([
        'user_id'         => $user->id,
        'organization_id' => $org->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/auth/me/host-status')
        ->assertOk()
        ->assertJsonPath('organization_id', $org->id);
});

test('returns own host status, not someone else\'s', function () {
    $user      = User::factory()->create();
    $otherUser = User::factory()->create();
    TournamentHost::factory()->create([
        'user_id'      => $otherUser->id,
        'display_name' => 'someone-else',
        'status'       => 'approved',
    ]);

    // Caller has no host of their own.
    $this->actingAs($user)
        ->getJson('/api/auth/me/host-status')
        ->assertOk()
        ->assertJson([
            'has_application' => false,
            'status'          => 'none',
        ]);
});
