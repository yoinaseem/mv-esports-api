<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// ---------------------------------------------------------------------------
// POST /auth/register
// ---------------------------------------------------------------------------

test('register creates a user, hashes the password, and returns a token + user payload', function () {
    $response = postJson('/api/auth/register', [
        'name'                  => 'Alice',
        'display_name'          => 'alice',
        'email'                 => 'alice@example.test',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
        'date_of_birth'         => '2000-01-01',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('user.email', 'alice@example.test')
        ->assertJsonPath('user.display_name', 'alice')
        ->assertJsonPath('user.country', 'MV') // DB default
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'display_name', 'email', 'date_of_birth', 'country', 'roles', 'permissions'],
        ]);

    $user = User::where('email', 'alice@example.test')->firstOrFail();
    expect($user->password)->not->toBe('password123');           // hashed
    expect($user->date_of_birth->toDateString())->toBe('2000-01-01');
    expect($user->getRoleNames())->toBeEmpty();                  // no default role
});

test('register rejects an applicant under 13 (age-gate per DESIGN.md §11.1)', function () {
    $under13 = now()->subYears(12)->subDay()->toDateString();

    postJson('/api/auth/register', [
        'name'                  => 'Bobby',
        'email'                 => 'bobby@example.test',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
        'date_of_birth'         => $under13,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['date_of_birth']);

    expect(User::where('email', 'bobby@example.test')->exists())->toBeFalse();
});

test('register rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    postJson('/api/auth/register', [
        'email'                 => 'taken@example.test',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
        'date_of_birth'         => '2000-01-01',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('register rejects mismatched password confirmation', function () {
    postJson('/api/auth/register', [
        'email'                 => 'mismatch@example.test',
        'password'              => 'password123',
        'password_confirmation' => 'different456',
        'date_of_birth'         => '2000-01-01',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

// ---------------------------------------------------------------------------
// POST /auth/login
// ---------------------------------------------------------------------------

test('login returns a token + user on valid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'password123',
    ])
        ->assertOk()
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles', 'permissions']]);
});

test('login rejects bad credentials with a 422', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'wrong-password',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

// ---------------------------------------------------------------------------
// GET /auth/me
// ---------------------------------------------------------------------------

test('me requires authentication', function () {
    getJson('/api/auth/me')->assertUnauthorized();
});

test('me returns the authenticated user with roles and permissions', function () {
    $user = User::factory()->systemManager()->create();

    $this->actingAs($user)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('user.roles.0', 'system_manager')
        ->assertJsonPath('user.permissions.0', 'users.view');
});

// ---------------------------------------------------------------------------
// POST /auth/logout
// ---------------------------------------------------------------------------

test('logout revokes the current access token', function () {
    $user      = User::factory()->create();
    $newToken  = $user->createToken('api');
    $tokenId   = $newToken->accessToken->id;
    $plainText = $newToken->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$plainText}")
        ->postJson('/api/auth/logout')
        ->assertOk();

    // Token row deleted — direct DB assertion (more reliable than re-issuing
    // a request, since TestCase state leaks auth between calls within a test).
    expect(PersonalAccessToken::find($tokenId))->toBeNull();
});
