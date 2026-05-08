<?php

use App\Models\Stage;
use App\Models\StageQualification;

test('a qualification belongs to a target stage and optionally a source stage', function () {
    $source = Stage::factory()->create();
    $target = Stage::factory()->create();
    $q      = StageQualification::factory()->create([
        'source_stage_id' => $source->id,
        'target_stage_id' => $target->id,
    ]);

    expect($q->source)->toBeInstanceOf(Stage::class);
    expect($q->target)->toBeInstanceOf(Stage::class);
    expect($q->source->id)->toBe($source->id);
    expect($q->target->id)->toBe($target->id);
});

test('source can be null (rule pulls from tournament registrations)', function () {
    $target = Stage::factory()->create();
    $q      = StageQualification::factory()->fromRegistrations()->create([
        'target_stage_id' => $target->id,
    ]);

    expect($q->source_stage_id)->toBeNull();
    expect($q->source)->toBeNull();
    expect($q->target)->toBeInstanceOf(Stage::class);
});

test('rule_config is cast as array', function () {
    $q = StageQualification::factory()->topNPerGroup(2)->create();

    expect($q->rule_config)->toBeArray();
    expect($q->rule_config['per_group'])->toBe(2);
    expect($q->rule_config['placement_strategy'])->toBe('cross_group');
});

test('factory states set the corresponding rule_type and config', function () {
    expect(StageQualification::factory()->topNPerGroup(3)->create()->rule_type)->toBe('top_n_per_group');
    expect(StageQualification::factory()->manual()->create()->rule_config)->toBe([]);
    expect(StageQualification::factory()->all()->create()->rule_type)->toBe('all');
});

test('deleting a target stage cascades to its qualifications', function () {
    $target = Stage::factory()->create();
    StageQualification::factory()->count(2)->create(['target_stage_id' => $target->id]);

    $target->delete();

    expect(StageQualification::where('target_stage_id', $target->id)->count())->toBe(0);
});

test('deleting a source stage cascades to qualifications referencing it', function () {
    $source = Stage::factory()->create();
    $target = Stage::factory()->create();
    StageQualification::factory()->create([
        'source_stage_id' => $source->id,
        'target_stage_id' => $target->id,
    ]);

    $source->delete();

    expect(StageQualification::where('target_stage_id', $target->id)->count())->toBe(0);
});

test('Stage::outgoingQualifications and Stage::incomingQualifications resolve', function () {
    $source = Stage::factory()->create();
    $target = Stage::factory()->create();
    StageQualification::factory()->create([
        'source_stage_id' => $source->id,
        'target_stage_id' => $target->id,
    ]);

    expect($source->outgoingQualifications->count())->toBe(1);
    expect($target->incomingQualifications->count())->toBe(1);
    expect($source->incomingQualifications->count())->toBe(0);
});
