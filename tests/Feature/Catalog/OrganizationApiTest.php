<?php

use App\Models\Organization;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// ---------------------------------------------------------------------------
// GET /api/organizations  +  GET /api/organizations/{id}
// ---------------------------------------------------------------------------

test('index returns all organisations publicly', function () {
    Organization::factory()->count(3)->create();

    getJson('/api/organizations')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('show returns the requested organisation', function () {
    $org = Organization::factory()->create();

    getJson("/api/organizations/{$org->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $org->id);
});

// ---------------------------------------------------------------------------
// POST /api/organizations  (any auth user)
// ---------------------------------------------------------------------------

test('store rejects unauthenticated requests', function () {
    postJson('/api/organizations', ['name' => 'X', 'slug' => 'x'])->assertUnauthorized();
});

test('any authenticated user can create an organisation and becomes the owner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/organizations', [
            'name' => 'MV Pros',
            'slug' => 'mv-pros',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.slug', 'mv-pros')
        ->assertJsonPath('data.owner_user_id', $user->id);
});

// ---------------------------------------------------------------------------
// PATCH /api/organizations/{id}
// ---------------------------------------------------------------------------

test('the owner can patch their organisation', function () {
    $owner = User::factory()->create();
    $org   = Organization::factory()->create(['owner_user_id' => $owner->id]);

    $this->actingAs($owner)
        ->patchJson("/api/organizations/{$org->id}", ['description' => 'Updated description.'])
        ->assertOk()
        ->assertJsonPath('data.description', 'Updated description.');
});

test('a non-owner authenticated user cannot patch an organisation', function () {
    $stranger = User::factory()->create();
    $org      = Organization::factory()->create(); // owner is some other user

    $this->actingAs($stranger)
        ->patchJson("/api/organizations/{$org->id}", ['description' => 'Hijacked.'])
        ->assertForbidden();
});

test('a system_manager cannot patch an organisation they do not own', function () {
    // DESIGN.md §5: orgs are community-created and gated by ownership;
    // system_manager does NOT auto-manage them.
    $manager = User::factory()->systemManager()->create();
    $org     = Organization::factory()->create();

    $this->actingAs($manager)
        ->patchJson("/api/organizations/{$org->id}", ['description' => 'X'])
        ->assertForbidden();
});

test('a superadmin can patch any organisation', function () {
    $admin = User::factory()->superadmin()->create();
    $org   = Organization::factory()->create();

    $this->actingAs($admin)
        ->patchJson("/api/organizations/{$org->id}", ['description' => 'Admin override.'])
        ->assertOk();
});

// ---------------------------------------------------------------------------
// DELETE — soft delete behaviour
// ---------------------------------------------------------------------------

test('the owner can soft-delete their organisation', function () {
    $owner = User::factory()->create();
    $org   = Organization::factory()->create(['owner_user_id' => $owner->id]);

    $this->actingAs($owner)
        ->deleteJson("/api/organizations/{$org->id}")
        ->assertOk();

    expect(Organization::find($org->id))->toBeNull();
    expect(Organization::withTrashed()->find($org->id))->not->toBeNull();
});
