<?php

use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\Tournament;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('index returns stages for a tournament sorted by sort_order', function () {
    $tournament = Tournament::factory()->draft()->create();
    Stage::factory()->for($tournament)->create(['sort_order' => 1, 'name' => 'Playoffs']);
    Stage::factory()->for($tournament)->create(['sort_order' => 0, 'name' => 'Group Stage']);

    $r = getJson("/api/tournaments/{$tournament->id}/stages")->assertOk();
    expect($r->json('data.0.name'))->toBe('Group Stage');
    expect($r->json('data.1.name'))->toBe('Playoffs');
});

test('show returns a single stage', function () {
    $stage = Stage::factory()->create();
    getJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $stage->id);
});

test('show returns 404 for cross-tournament tampering', function () {
    $a = Tournament::factory()->draft()->create();
    $b = Tournament::factory()->draft()->create();
    $stageOfB = Stage::factory()->for($b)->create();

    getJson("/api/tournaments/{$a->id}/stages/{$stageOfB->id}")->assertNotFound();
});

test('store rejects unauthenticated callers', function () {
    $tournament = Tournament::factory()->draft()->create();
    postJson("/api/tournaments/{$tournament->id}/stages", [])->assertUnauthorized();
});

test('store rejects non-admin callers (403)', function () {
    $tournament = Tournament::factory()->draft()->create();
    $stranger   = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name' => 'Group Stage', 'format' => 'single_elim',
        ])
        ->assertForbidden();
});

test('the tournament creator can add a stage in Draft', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Main Event',
            'format' => 'single_elim',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Main Event')
        ->assertJsonPath('data.sort_order', 0);
});

test('store auto-increments sort_order if omitted', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    Stage::factory()->for($tournament)->create(['sort_order' => 0]);
    Stage::factory()->for($tournament)->create(['sort_order' => 5]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name' => 'Next', 'format' => 'single_elim',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.sort_order', 6);
});

test('store enforces format-specific config validation (round_robin requires int groups)', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Bad config',
            'format' => 'round_robin',
            'config' => ['groups' => 'two', 'group_size' => 4],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['config']);
});

test('store rejects unknown config keys per format', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name'   => 'Single elim with extra',
            'format' => 'single_elim',
            'config' => ['third_place_match' => true, 'unknown_key' => 'x'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['config']);
});

test('structure is locked once registration_closed', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state(['status' => TournamentStatus::RegistrationClosed])->create([
        'created_by_user_id' => $creator->id,
    ]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name' => 'Too late', 'format' => 'single_elim',
        ])
        ->assertStatus(422);
});

test('structure is unlocked during RegistrationOpen', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->registrationOpen()->create(['created_by_user_id' => $creator->id]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages", [
            'name' => 'Late add', 'format' => 'single_elim',
        ])
        ->assertStatus(201);
});

test('update changes name and re-validates config against format', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create(['format' => 'single_elim']);

    $this->actingAs($creator)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'format' => 'double_elim',
            'config' => ['grand_final_reset' => true],
        ])
        ->assertOk()
        ->assertJsonPath('data.format', 'double_elim');
});

test('delete only allowed for pending stages', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->inProgress()->create();

    $this->actingAs($creator)
        ->deleteJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}")
        ->assertStatus(422);
});

test('reorder atomically swaps sort_orders', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $a          = Stage::factory()->for($tournament)->create(['sort_order' => 0]);
    $b          = Stage::factory()->for($tournament)->create(['sort_order' => 1]);
    $c          = Stage::factory()->for($tournament)->create(['sort_order' => 2]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/reorder", [
            'stages' => [
                ['id' => $a->id, 'sort_order' => 2],
                ['id' => $b->id, 'sort_order' => 0],
                ['id' => $c->id, 'sort_order' => 1],
            ],
        ])
        ->assertOk();

    expect($a->fresh()->sort_order)->toBe(2);
    expect($b->fresh()->sort_order)->toBe(0);
    expect($c->fresh()->sort_order)->toBe(1);
});

test('reorder rejects ids not belonging to the tournament', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $own        = Stage::factory()->for($tournament)->create(['sort_order' => 0]);
    $foreign    = Stage::factory()->create(['sort_order' => 0]); // different tournament

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/reorder", [
            'stages' => [
                ['id' => $own->id, 'sort_order' => 0],
                ['id' => $foreign->id, 'sort_order' => 1],
            ],
        ])
        ->assertStatus(422);
});
