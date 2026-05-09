<?php

use function Pest\Laravel\getJson;

test('health endpoint returns 200 ok when DB is reachable', function () {
    getJson('/api/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'db'     => 'ok',
        ])
        ->assertJsonStructure(['status', 'timestamp', 'db']);
});

test('health endpoint does not require authentication', function () {
    // No actingAs() — anonymous request.
    getJson('/api/health')->assertOk();
});

test('health endpoint timestamp is ISO 8601', function () {
    $r = getJson('/api/health')->assertOk();

    $ts = $r->json('timestamp');
    expect($ts)->toBeString();
    // Parseable as ISO 8601 — Carbon parses it without throwing.
    expect(\Illuminate\Support\Carbon::parse($ts))->toBeInstanceOf(\Carbon\Carbon::class);
});
