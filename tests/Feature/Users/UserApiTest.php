<?php

use App\Models\Organization;
use App\Models\User;

use function Pest\Laravel\getJson;

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

test('index requires authentication', function () {
    getJson('/api/users')->assertUnauthorized();
});

test('index rejects non-admin callers', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/users')
        ->assertForbidden();
});

test('system_manager can list users', function () {
    User::factory()->count(3)->create();

    $this->actingAs(User::factory()->systemManager()->create())
        ->getJson('/api/users')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'email', 'name']]]);
});

test('superadmin can include soft-deleted users via ?include_deleted=1', function () {
    $admin = User::factory()->superadmin()->create();
    $deleted = User::factory()->create();
    $deleted->delete();

    $defaultCount = $this->actingAs($admin)
        ->getJson('/api/users')
        ->assertOk()
        ->json('data');
    $withDeletedCount = $this->actingAs($admin)
        ->getJson('/api/users?include_deleted=1')
        ->assertOk()
        ->json('data');

    expect(count($withDeletedCount))->toBeGreaterThan(count($defaultCount));
});

test('include_deleted is silently ignored for non-superadmins', function () {
    $manager = User::factory()->systemManager()->create();
    $deletedUser = User::factory()->create();
    $deletedUser->delete();

    // Should NOT include the soft-deleted user even with the flag.
    $r = $this->actingAs($manager)->getJson('/api/users?include_deleted=1')->assertOk();
    $emails = collect($r->json('data'))->pluck('email');
    expect($emails)->not->toContain($deletedUser->email);
});

// ---------------------------------------------------------------------------
// Show
// ---------------------------------------------------------------------------

test('show requires admin authorisation', function () {
    $target = User::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/users/{$target->id}")
        ->assertForbidden();
});

test('admin can show a user', function () {
    $target = User::factory()->create();

    $this->actingAs(User::factory()->systemManager()->create())
        ->getJson("/api/users/{$target->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $target->id);
});

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

test('store rejects non-admin callers', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/users', [
            'email'                 => 'new@example.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'date_of_birth'         => '2000-01-01',
        ])
        ->assertForbidden();
});

test('system_manager can create a user', function () {
    $payload = [
        'name'                  => 'Created By Admin',
        'display_name'          => 'created-by-admin',
        'email'                 => 'created@example.test',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
        'date_of_birth'         => '2000-01-01',
        'country'               => 'MV',
    ];

    $this->actingAs(User::factory()->systemManager()->create())
        ->postJson('/api/users', $payload)
        ->assertStatus(201)
        ->assertJsonPath('data.email', 'created@example.test')
        ->assertJsonPath('data.name', 'Created By Admin');
});

test('store does not return a token', function () {
    $r = $this->actingAs(User::factory()->systemManager()->create())
        ->postJson('/api/users', [
            'email'                 => 'no-token@example.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'date_of_birth'         => '2000-01-01',
        ])
        ->assertStatus(201);

    expect($r->json())->not->toHaveKey('token');
});

test('store enforces age gate (rejects under-13 dates)', function () {
    $this->actingAs(User::factory()->systemManager()->create())
        ->postJson('/api/users', [
            'email'                 => 'tooyoung@example.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'date_of_birth'         => now()->subYears(10)->toDateString(),
        ])
        ->assertStatus(422);
});

test('store rejects duplicate email', function () {
    $existing = User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs(User::factory()->systemManager()->create())
        ->postJson('/api/users', [
            'email'                 => 'taken@example.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'date_of_birth'         => '2000-01-01',
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Update
// ---------------------------------------------------------------------------

test('update rejects system_manager (superadmin-only per the tightened permission)', function () {
    $manager = User::factory()->systemManager()->create();
    $target  = User::factory()->create();

    $this->actingAs($manager)
        ->patchJson("/api/users/{$target->id}", ['name' => 'Updated'])
        ->assertForbidden();
});

test('superadmin can patch a user', function () {
    $admin  = User::factory()->superadmin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->patchJson("/api/users/{$target->id}", [
            'name'         => 'New Name',
            'display_name' => 'new-handle',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.display_name', 'new-handle');
});

test('update enforces email uniqueness (skipping the row being edited)', function () {
    $admin  = User::factory()->superadmin()->create();
    $other  = User::factory()->create(['email' => 'other@example.test']);
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->patchJson("/api/users/{$target->id}", ['email' => 'other@example.test'])
        ->assertStatus(422);

    // Same email on the same row is fine (no-op uniqueness).
    $this->actingAs($admin)
        ->patchJson("/api/users/{$target->id}", ['email' => $target->email])
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Destroy
// ---------------------------------------------------------------------------

test('user can self-delete', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/api/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Account deleted.');

    expect(User::withTrashed()->find($user->id)->deleted_at)->not->toBeNull();
});

test('system_manager cannot delete another user', function () {
    $manager = User::factory()->systemManager()->create();
    $target  = User::factory()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/users/{$target->id}")
        ->assertForbidden();
});

test('superadmin can delete another user', function () {
    $admin  = User::factory()->superadmin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/users/{$target->id}")
        ->assertOk();

    expect(User::withTrashed()->find($target->id)->name)->toBe('[deleted user]');
});

test('self-delete returns 422 if user owns active organisations', function () {
    $user = User::factory()->create();
    Organization::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/users/{$user->id}")
        ->assertStatus(422);
});

test('superadmin deleting a user with active organisations is also blocked', function () {
    $admin  = User::factory()->superadmin()->create();
    $target = User::factory()->create();
    Organization::factory()->create(['owner_user_id' => $target->id]);

    $this->actingAs($admin)
        ->deleteJson("/api/users/{$target->id}")
        ->assertStatus(422);

    // Target still active.
    expect(User::find($target->id))->not->toBeNull();
});

test('delete is unauthenticated → 401', function () {
    $target = User::factory()->create();

    $this->deleteJson("/api/users/{$target->id}")
        ->assertUnauthorized();
});
