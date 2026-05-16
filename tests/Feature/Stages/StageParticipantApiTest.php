<?php

use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;

use function Pest\Laravel\getJson;

test('index lists participants in a stage sorted by seed', function () {
    $stage = Stage::factory()->create();
    StageParticipant::factory()->for($stage)->create(['seed' => 3]);
    StageParticipant::factory()->for($stage)->create(['seed' => 1]);
    StageParticipant::factory()->for($stage)->create(['seed' => 2]);

    $r = getJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}/participants")
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect($r->json('data.0.seed'))->toBe(1);
    expect($r->json('data.2.seed'))->toBe(3);
});

test('index supports filtering by status and group_number', function () {
    $stage = Stage::factory()->create();
    StageParticipant::factory()->for($stage)->inGroup(1)->create();
    StageParticipant::factory()->for($stage)->inGroup(2)->create();
    StageParticipant::factory()->for($stage)->inGroup(2)->withdrawn()->create();

    getJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}/participants?group_number=2")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    getJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}/participants?status=withdrawn")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('store rejects non-admin callers', function () {
    $stage = Stage::factory()->create();
    $team  = Team::factory()->create(['game_id' => $stage->tournament->game_id]);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}/participants", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => 1,
        ])
        ->assertForbidden();
});

test('admin can add a team participant', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $team       = Team::factory()->create(['game_id' => $tournament->game_id]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => 1,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.participant_id', $team->id)
        ->assertJsonPath('data.seed', 1);
});

test('admin can add a participant when stage has only manual incoming qualifications', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    \App\Models\StageQualification::factory()->manual()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);
    $team = Team::factory()->create(['game_id' => $tournament->game_id]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => 1,
        ])
        ->assertStatus(201);
});

test('store rejects participant POST when stage has an auto-resolving qualification', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    \App\Models\StageQualification::factory()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
        'rule_type'       => 'top_n',
        'rule_config'     => ['n' => 8],
    ]);
    $team = Team::factory()->create(['game_id' => $tournament->game_id]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => 1,
        ])
        ->assertStatus(422);
});

test('store rejects participant POST when ANY incoming qualification is auto-resolving (mixed manual + all)', function () {
    // If a stage has multiple incoming qualifications and any is non-manual,
    // the resolver still owns participant population — manual POST rejected.
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    \App\Models\StageQualification::factory()->manual()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);
    \App\Models\StageQualification::factory()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);
    $team = Team::factory()->create(['game_id' => $tournament->game_id]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => 1,
        ])
        ->assertStatus(422);
});

test('store rejects participant_type that does not match tournament', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->playerType()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $team       = Team::factory()->create(['game_id' => $tournament->game_id]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => 1,
        ])
        ->assertStatus(422);
});

test('store rejects duplicate participant in the same stage', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $team       = Team::factory()->create(['game_id' => $tournament->game_id]);

    StageParticipant::factory()->for($stage)->create([
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]);

    $this->actingAs($creator)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants", [
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => 5,
        ])
        ->assertStatus(422);
});

test('admin can patch seed, group_number, status', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $sp         = StageParticipant::factory()->for($stage)->create(['seed' => 1]);

    $this->actingAs($creator)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants/{$sp->id}", [
            'seed'         => 7,
            'group_number' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('data.seed', 7)
        ->assertJsonPath('data.group_number', 2);
});

test('seed remains mutable in registration_closed (host recovery window)', function () {
    // RegistrationClosed is the recovery window — host can still re-seed
    // before seed-and-build builds the bracket. The lock is at InProgress.
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state(['status' => \App\Enums\TournamentStatus::RegistrationClosed])->create([
        'created_by_user_id' => $creator->id,
    ]);
    $stage = Stage::factory()->for($tournament)->create();
    $sp    = StageParticipant::factory()->for($stage)->create(['seed' => 1]);

    $this->actingAs($creator)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants/{$sp->id}", [
            'seed' => 5,
        ])
        ->assertOk()
        ->assertJsonPath('data.seed', 5);
});

test('after InProgress, seed cannot be changed but status can', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state(['status' => \App\Enums\TournamentStatus::InProgress])->create([
        'created_by_user_id' => $creator->id,
    ]);
    $stage = Stage::factory()->for($tournament)->create();
    $sp    = StageParticipant::factory()->for($stage)->create();

    // Seed change rejected
    $this->actingAs($creator)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants/{$sp->id}", [
            'seed' => 5,
        ])
        ->assertStatus(422);

    // Status change allowed (driven by match advancement)
    $this->actingAs($creator)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants/{$sp->id}", [
            'status'         => 'eliminated',
            'final_position' => 4,
        ])
        ->assertOk();
});

test('cross-stage tampering returns 404 on update', function () {
    $creator   = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stageA    = Stage::factory()->for($tournament)->create(['sort_order' => 0]);
    $stageB    = Stage::factory()->for($tournament)->create(['sort_order' => 1]);
    $spOfB     = StageParticipant::factory()->for($stageB)->create();

    $this->actingAs($creator)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stageA->id}/participants/{$spOfB->id}", [
            'seed' => 5,
        ])
        ->assertNotFound();
});

test('admin can delete a stage participant while structure unlocked', function () {
    $creator    = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $creator->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $sp         = StageParticipant::factory()->for($stage)->create();

    $this->actingAs($creator)
        ->deleteJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/participants/{$sp->id}")
        ->assertOk();

    expect(StageParticipant::find($sp->id))->toBeNull();
});
