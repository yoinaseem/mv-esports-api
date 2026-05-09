<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

/**
 * Regression tests for the public-tournament-routes-on-Bearer-token bug.
 *
 * The default `web` guard is session-based and doesn't read Bearer tokens
 * sent by the SPA. On routes inside the auth:sanctum middleware group this
 * doesn't matter — the middleware switches to Sanctum's guard. But on
 * PUBLIC routes (tournament index / show — anonymous viewers must see
 * published tournaments), the default-guard user resolves to null even
 * with a valid Bearer token. The fix uses `$request->user('sanctum')`
 * explicitly on those endpoints.
 *
 * Uses Sanctum::actingAs() (which authenticates via the sanctum guard)
 * instead of Pest's `actingAs()` (which authenticates on the default guard
 * and would mask the bug).
 */

test('show: superadmin with Bearer token can view their own draft tournament', function () {
    $admin = User::factory()->superadmin()->create();
    $draft = Tournament::factory()->state([
        'status'             => TournamentStatus::Draft,
        'created_by_user_id' => $admin->id,
    ])->create();

    Sanctum::actingAs($admin);

    getJson("/api/tournaments/{$draft->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $draft->id);
});

test('show: anonymous viewer (no token) cannot view a draft tournament', function () {
    $draft = Tournament::factory()->state(['status' => TournamentStatus::Draft])->create();

    getJson("/api/tournaments/{$draft->id}")->assertNotFound();
});

test('show: token-bearing user who is not the creator cannot view someone else\'s draft', function () {
    $stranger = User::factory()->create();
    $draft    = Tournament::factory()->state(['status' => TournamentStatus::Draft])->create();

    Sanctum::actingAs($stranger);

    getJson("/api/tournaments/{$draft->id}")->assertNotFound();
});

test('show: system_manager via Bearer token can view any draft', function () {
    $manager = User::factory()->systemManager()->create();
    $draft   = Tournament::factory()->state(['status' => TournamentStatus::Draft])->create();

    Sanctum::actingAs($manager);

    getJson("/api/tournaments/{$draft->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $draft->id);
});

test('index: superadmin with Bearer token sees their drafts when ?include_drafts=1', function () {
    $admin = User::factory()->superadmin()->create();
    Tournament::factory()->state([
        'status'             => TournamentStatus::Draft,
        'created_by_user_id' => $admin->id,
        'name'               => 'Admin Draft Cup',
    ])->create();

    Sanctum::actingAs($admin);

    $r = getJson('/api/tournaments?include_drafts=1')->assertOk();
    $names = collect($r->json('data'))->pluck('name')->toArray();
    expect($names)->toContain('Admin Draft Cup');
});

test('index: anonymous viewer with ?include_drafts=1 still gets drafts filtered out', function () {
    Tournament::factory()->state([
        'status' => TournamentStatus::Draft,
        'name'   => 'Hidden Draft',
    ])->create();
    Tournament::factory()->registrationOpen()->create(['name' => 'Public Cup']);

    $r = getJson('/api/tournaments?include_drafts=1')->assertOk();
    $names = collect($r->json('data'))->pluck('name')->toArray();
    expect($names)->toContain('Public Cup');
    expect($names)->not->toContain('Hidden Draft');
});
