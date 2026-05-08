<?php

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;

use function Pest\Laravel\getJson;

// ---------------------------------------------------------------------------
// GET /api/organizations/{id}/members
// ---------------------------------------------------------------------------

test('index lists members publicly', function () {
    $org = Organization::factory()->create();
    OrganizationMember::factory()->for($org)->count(2)->create();
    OrganizationMember::factory()->for($org)->left()->create();

    getJson("/api/organizations/{$org->id}/members")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('index supports an active=1 filter', function () {
    $org = Organization::factory()->create();
    OrganizationMember::factory()->for($org)->count(2)->create();
    OrganizationMember::factory()->for($org)->left()->create();

    getJson("/api/organizations/{$org->id}/members?active=1")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ---------------------------------------------------------------------------
// POST /api/organizations/{id}/members
// ---------------------------------------------------------------------------

test('a non-owner cannot add a member', function () {
    $stranger = User::factory()->create();
    $org      = Organization::factory()->create();
    $invitee  = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson("/api/organizations/{$org->id}/members", [
            'user_id' => $invitee->id,
            'role'    => 'member',
        ])
        ->assertForbidden();
});

test('the owner can add a member', function () {
    $owner   = User::factory()->create();
    $org     = Organization::factory()->create(['owner_user_id' => $owner->id]);
    $invitee = User::factory()->create();

    $this->actingAs($owner)
        ->postJson("/api/organizations/{$org->id}/members", [
            'user_id' => $invitee->id,
            'role'    => 'staff',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.user_id', $invitee->id)
        ->assertJsonPath('data.role', 'staff');
});

test('store rejects an unknown user_id', function () {
    $owner = User::factory()->create();
    $org   = Organization::factory()->create(['owner_user_id' => $owner->id]);

    $this->actingAs($owner)
        ->postJson("/api/organizations/{$org->id}/members", [
            'user_id' => 99999,
            'role'    => 'member',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_id']);
});

test('store rejects an unknown role value', function () {
    $owner   = User::factory()->create();
    $org     = Organization::factory()->create(['owner_user_id' => $owner->id]);
    $invitee = User::factory()->create();

    $this->actingAs($owner)
        ->postJson("/api/organizations/{$org->id}/members", [
            'user_id' => $invitee->id,
            'role'    => 'admin', // not a valid role
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role']);
});

// ---------------------------------------------------------------------------
// PATCH + DELETE
// ---------------------------------------------------------------------------

test('the owner can change a member role', function () {
    $owner  = User::factory()->create();
    $org    = Organization::factory()->create(['owner_user_id' => $owner->id]);
    $member = OrganizationMember::factory()->for($org)->create(['role' => 'member']);

    $this->actingAs($owner)
        ->patchJson("/api/organizations/{$org->id}/members/{$member->id}", ['role' => 'staff'])
        ->assertOk()
        ->assertJsonPath('data.role', 'staff');
});

test('the owner can remove a member (cascade-safe via direct delete)', function () {
    $owner  = User::factory()->create();
    $org    = Organization::factory()->create(['owner_user_id' => $owner->id]);
    $member = OrganizationMember::factory()->for($org)->create();

    $this->actingAs($owner)
        ->deleteJson("/api/organizations/{$org->id}/members/{$member->id}")
        ->assertOk();

    expect(OrganizationMember::find($member->id))->toBeNull();
});

test('cross-organisation tampering returns 404', function () {
    $ownerA  = User::factory()->create();
    $orgA    = Organization::factory()->create(['owner_user_id' => $ownerA->id]);
    $orgB    = Organization::factory()->create();
    $memberB = OrganizationMember::factory()->for($orgB)->create();

    // ownerA tries to update a member that belongs to orgB
    $this->actingAs($ownerA)
        ->patchJson("/api/organizations/{$orgA->id}/members/{$memberB->id}", ['role' => 'staff'])
        ->assertNotFound();
});
