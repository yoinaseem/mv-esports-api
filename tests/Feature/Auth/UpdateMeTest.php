<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('rejects unauthenticated callers', function () {
    $this->patchJson('/api/auth/me', ['display_name' => 'new'])
        ->assertUnauthorized();
});

test('updates name and display_name', function () {
    $user = User::factory()->create([
        'name'         => 'Original',
        'display_name' => 'original',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/auth/me', [
            'name'         => 'Updated Name',
            'display_name' => 'updated_handle',
        ])
        ->assertOk()
        ->assertJsonPath('user.name', 'Updated Name')
        ->assertJsonPath('user.display_name', 'updated_handle');
});

test('email is lowercased on save', function () {
    $user = User::factory()->create(['email' => 'old@example.test']);

    $this->actingAs($user)
        ->patchJson('/api/auth/me', ['email' => 'NEW.MIXED@Example.TEST'])
        ->assertOk();

    expect($user->fresh()->email)->toBe('new.mixed@example.test');
});

test('email uniqueness skips the caller and rejects collisions with another user', function () {
    $caller = User::factory()->create(['email' => 'caller@example.test']);
    $other  = User::factory()->create(['email' => 'taken@example.test']);

    // Same email on caller's own row → no-op uniqueness, allowed.
    $this->actingAs($caller)
        ->patchJson('/api/auth/me', ['email' => 'caller@example.test'])
        ->assertOk();

    // Email already used by another user → 422.
    $this->actingAs($caller)
        ->patchJson('/api/auth/me', ['email' => 'taken@example.test'])
        ->assertStatus(422);
});

test('password change requires current_password', function () {
    $user = User::factory()->create(['password' => Hash::make('original-pw')]);

    // Missing current_password → 422.
    $this->actingAs($user)
        ->patchJson('/api/auth/me', [
            'password'              => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);
});

test('password change rejects wrong current_password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-pw')]);

    $this->actingAs($user)
        ->patchJson('/api/auth/me', [
            'current_password'      => 'wrong-pw',
            'password'              => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertStatus(422);
});

test('password change rejects without password_confirmation match', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-pw')]);

    $this->actingAs($user)
        ->patchJson('/api/auth/me', [
            'current_password'      => 'correct-pw',
            'password'              => 'new-password',
            'password_confirmation' => 'mismatched',
        ])
        ->assertStatus(422);
});

test('password change with correct current_password and matching confirmation succeeds', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-pw')]);

    $this->actingAs($user)
        ->patchJson('/api/auth/me', [
            'current_password'      => 'correct-pw',
            'password'              => 'fresh-password-123',
            'password_confirmation' => 'fresh-password-123',
        ])
        ->assertOk();

    // The new password is in effect — old one no longer authenticates.
    $user->refresh();
    expect(Hash::check('fresh-password-123', $user->password))->toBeTrue();
    expect(Hash::check('correct-pw', $user->password))->toBeFalse();
});

test('current_password is not persisted to the user', function () {
    // Defensive: ensure the guard field never sneaks into the user row.
    $user = User::factory()->create(['password' => Hash::make('correct-pw')]);

    $this->actingAs($user)
        ->patchJson('/api/auth/me', [
            'current_password'      => 'correct-pw',
            'password'              => 'fresh-password-123',
            'password_confirmation' => 'fresh-password-123',
        ])
        ->assertOk();

    // No 'current_password' attribute on the User model.
    expect($user->fresh()->getAttributes())->not->toHaveKey('current_password');
});

test('underage date_of_birth is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/api/auth/me', [
            'date_of_birth' => now()->subYears(10)->toDateString(),
        ])
        ->assertStatus(422);
});

test('country accepts a 2-char code', function () {
    $user = User::factory()->create(['country' => 'MV']);

    $this->actingAs($user)
        ->patchJson('/api/auth/me', ['country' => 'US'])
        ->assertOk()
        ->assertJsonPath('user.country', 'US');
});
