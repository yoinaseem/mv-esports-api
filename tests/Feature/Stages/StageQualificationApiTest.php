<?php

use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Tournament;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('index lists incoming qualifications for a stage', function () {
    $tournament = Tournament::factory()->draft()->create();
    $stage      = Stage::factory()->for($tournament)->create();
    StageQualification::factory()->count(2)->create(['target_stage_id' => $stage->id]);

    getJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('store rejects non-admin callers', function () {
    $tournament = Tournament::factory()->draft()->create();
    $stage      = Stage::factory()->for($tournament)->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications", [
            'rule_type' => 'all',
        ])
        ->assertForbidden();
});

test('admin can add a from-registrations rule (null source)', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications", [
            'source_stage_id' => null,
            'rule_type'       => 'all',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.source_stage_id', null)
        ->assertJsonPath('data.target_stage_id', $stage->id);
});

test('admin can add a top_n rule with valid config', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $source     = Stage::factory()->for($tournament)->create(['sort_order' => 0]);
    $target     = Stage::factory()->for($tournament)->create(['sort_order' => 1]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$target->id}/qualifications", [
            'source_stage_id' => $source->id,
            'rule_type'       => 'top_n',
            'rule_config'     => ['n' => 4],
        ])
        ->assertStatus(201);
});

test('store rejects malformed rule_config for top_n', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $source     = Stage::factory()->for($tournament)->create(['sort_order' => 0]);
    $target     = Stage::factory()->for($tournament)->create(['sort_order' => 1]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$target->id}/qualifications", [
            'source_stage_id' => $source->id,
            'rule_type'       => 'top_n',
            'rule_config'     => ['n' => 'four'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rule_config']);
});

test('store rejects self-referential qualification', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications", [
            'source_stage_id' => $stage->id,
            'rule_type'       => 'all',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_stage_id']);
});

test('store rejects a cycle in the qualification graph', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $a          = Stage::factory()->for($tournament)->create(['sort_order' => 0]);
    $b          = Stage::factory()->for($tournament)->create(['sort_order' => 1]);

    // Existing edge: A → B
    StageQualification::factory()->all()->create([
        'source_stage_id' => $a->id,
        'target_stage_id' => $b->id,
    ]);

    // Adding B → A would close the cycle.
    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$a->id}/qualifications", [
            'source_stage_id' => $b->id,
            'rule_type'       => 'all',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_stage_id']);
});

test('store rejects cross-tournament qualification', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournamentA = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $tournamentB = Tournament::factory()->draft()->create();
    $sourceB     = Stage::factory()->for($tournamentB)->create();
    $targetA     = Stage::factory()->for($tournamentA)->create();

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournamentA->id}/stages/{$targetA->id}/qualifications", [
            'source_stage_id' => $sourceB->id,
            'rule_type'       => 'all',
        ])
        ->assertStatus(422);
});

test('admin can delete a qualification rule', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $q          = StageQualification::factory()->fromRegistrations()->all()->create([
        'target_stage_id' => $stage->id,
    ]);

    $this->actingAs($creator)
        ->deleteJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications/{$q->id}")
        ->assertOk();

    expect(StageQualification::find($q->id))->toBeNull();
});
