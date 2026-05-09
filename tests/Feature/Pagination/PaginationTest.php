<?php

use App\Models\Game;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;

use function Pest\Laravel\getJson;

test('index responses include pagination meta + links keys', function () {
    Game::factory()->count(3)->create();

    $r = getJson('/api/games')->assertOk();

    expect($r->json())->toHaveKeys(['data', 'meta', 'links']);
    expect($r->json('meta'))->toHaveKeys(['current_page', 'last_page', 'per_page', 'total']);
    expect($r->json('links'))->toHaveKeys(['first', 'last', 'prev', 'next']);
});

test('per_page query param overrides the default', function () {
    Game::factory()->count(8)->create();

    $r = getJson('/api/games?per_page=3')->assertOk();

    expect($r->json('meta.per_page'))->toBe(3);
    expect($r->json('data'))->toHaveCount(3);
});

test('page query param navigates pages', function () {
    Game::factory()->count(8)->create();

    $page1 = getJson('/api/games?per_page=3&page=1')->assertOk();
    $page2 = getJson('/api/games?per_page=3&page=2')->assertOk();
    $page3 = getJson('/api/games?per_page=3&page=3')->assertOk();

    expect($page1->json('meta.current_page'))->toBe(1);
    expect($page2->json('meta.current_page'))->toBe(2);
    expect($page3->json('meta.current_page'))->toBe(3);

    expect($page1->json('data'))->toHaveCount(3);
    expect($page2->json('data'))->toHaveCount(3);
    expect($page3->json('data'))->toHaveCount(2); // 8 total → 3+3+2

    // Items don't repeat across pages.
    $allIds = collect()
        ->concat(collect($page1->json('data'))->pluck('id'))
        ->concat(collect($page2->json('data'))->pluck('id'))
        ->concat(collect($page3->json('data'))->pluck('id'));
    expect($allIds->unique()->count())->toBe(8);
});

test('per_page=0 is rejected with 422', function () {
    getJson('/api/games?per_page=0')->assertStatus(422);
});

test('per_page over 100 is rejected with 422', function () {
    getJson('/api/games?per_page=200')->assertStatus(422);
});

test('per_page non-integer is rejected with 422', function () {
    getJson('/api/games?per_page=abc')->assertStatus(422);
});

test('empty result set returns valid pagination meta', function () {
    Game::query()->delete();

    $r = getJson('/api/games')->assertOk();

    expect($r->json('data'))->toHaveCount(0);
    expect($r->json('meta.total'))->toBe(0);
    expect($r->json('meta.last_page'))->toBe(1);
});

test('pagination works on tournaments index', function () {
    // Default factory state is DraftPendingReview which is hidden from
    // anonymous viewers — use registrationOpen so they show up.
    Tournament::factory()->registrationOpen()->count(25)->create();

    $r = getJson('/api/tournaments?per_page=10')->assertOk();
    expect($r->json('data'))->toHaveCount(10);
    expect($r->json('meta.total'))->toBeGreaterThanOrEqual(25);
});

test('pagination works on admin users index (auth required)', function () {
    User::factory()->count(15)->create();
    $admin = User::factory()->systemManager()->create();

    $r = $this->actingAs($admin)
        ->getJson('/api/users?per_page=5')
        ->assertOk();

    expect($r->json('data'))->toHaveCount(5);
    expect($r->json('meta.per_page'))->toBe(5);
});

test('pagination works on team-members index (nested resource)', function () {
    $team = Team::factory()->create();
    \App\Models\TeamMember::factory()->count(10)->create(['team_id' => $team->id]);

    $r = getJson("/api/teams/{$team->id}/members?per_page=4")->assertOk();
    expect($r->json('data'))->toHaveCount(4);
    expect($r->json('meta.last_page'))->toBe(3); // 10 items / 4 per page = 3 pages
});
