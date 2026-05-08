<?php

use App\Enums\StageParticipantStatus;
use App\Models\Player;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Models\Tournament;

test('participant_type stores the morph alias', function () {
    $sp = StageParticipant::factory()->create();
    expect($sp->participant_type)->toBe('team');
});

test('participant relation resolves to the morphed model', function () {
    $sp = StageParticipant::factory()->create();
    expect($sp->participant)->toBeInstanceOf(Team::class);
});

test('player participants resolve correctly', function () {
    $tournament = Tournament::factory()->playerType()->create();
    $stage      = Stage::factory()->for($tournament)->create();
    $player     = Player::factory()->create(['game_id' => $tournament->game_id]);

    $sp = StageParticipant::factory()->create([
        'stage_id'         => $stage->id,
        'participant_type' => 'player',
        'participant_id'   => $player->id,
    ]);

    expect($sp->participant)->toBeInstanceOf(Player::class);
    expect($sp->participant->id)->toBe($player->id);
});

test('status casts as backed enum', function () {
    $sp = StageParticipant::factory()->create();

    expect($sp->status)->toBeInstanceOf(StageParticipantStatus::class);
    expect($sp->status)->toBe(StageParticipantStatus::Active);
});

test('factory states set status and final_position', function () {
    $eliminated = StageParticipant::factory()->eliminated(7)->create();
    expect($eliminated->status)->toBe(StageParticipantStatus::Eliminated);
    expect($eliminated->final_position)->toBe(7);

    $withdrawn = StageParticipant::factory()->withdrawn()->create();
    expect($withdrawn->status)->toBe(StageParticipantStatus::Withdrawn);

    $grouped = StageParticipant::factory()->inGroup(2)->create();
    expect($grouped->group_number)->toBe(2);
});

test('Team::stageParticipations and Player::stageParticipations resolve', function () {
    $sp = StageParticipant::factory()->create();
    $team = $sp->participant;

    expect($team->stageParticipations->count())->toBe(1);
    expect($team->stageParticipations->first()->id)->toBe($sp->id);
});

test('deleting a stage cascades to its participants', function () {
    $stage = Stage::factory()->create();
    StageParticipant::factory()->count(3)->create(['stage_id' => $stage->id]);

    $stage->delete();

    expect(StageParticipant::where('stage_id', $stage->id)->count())->toBe(0);
});
