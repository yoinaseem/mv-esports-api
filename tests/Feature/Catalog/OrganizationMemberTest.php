<?php

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;

test('an organization member belongs to an organization and a user', function () {
    $member = OrganizationMember::factory()->create();

    expect($member->organization)->toBeInstanceOf(Organization::class);
    expect($member->user)->toBeInstanceOf(User::class);
});

test('a member can transition role from member to staff to owner', function () {
    $member = OrganizationMember::factory()->create();
    expect($member->role)->toBe('member');

    $member->update(['role' => 'staff']);
    expect($member->fresh()->role)->toBe('staff');

    $member->update(['role' => 'owner']);
    expect($member->fresh()->role)->toBe('owner');
});

test('the active scope excludes members with a non-null left_at', function () {
    $org = Organization::factory()->create();
    OrganizationMember::factory()->for($org)->create(); // active
    OrganizationMember::factory()->for($org)->left()->create(); // left

    expect($org->members()->count())->toBe(2);
    expect($org->activeMembers()->count())->toBe(1);
    expect(OrganizationMember::active()->where('organization_id', $org->id)->count())->toBe(1);
});

test('deleting an organization cascades to its members', function () {
    $org = Organization::factory()->create();
    OrganizationMember::factory()->for($org)->count(3)->create();
    expect(OrganizationMember::where('organization_id', $org->id)->count())->toBe(3);

    $org->forceDelete();

    expect(OrganizationMember::where('organization_id', $org->id)->count())->toBe(0);
});

test('deleting a user cascades to their organization memberships', function () {
    $user = User::factory()->create();
    $org  = Organization::factory()->create();
    OrganizationMember::factory()->for($org)->for($user)->create();

    $user->forceDelete();

    expect(OrganizationMember::where('user_id', $user->id)->count())->toBe(0);
});
