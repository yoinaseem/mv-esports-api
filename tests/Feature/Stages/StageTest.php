<?php

use App\Enums\StageStatus;
use App\Models\Stage;
use App\Models\Tournament;
use Illuminate\Database\QueryException;

test('a stage casts status as a backed enum and config as array', function () {
    $stage = Stage::factory()->doubleElim()->create();

    expect($stage->status)->toBeInstanceOf(StageStatus::class);
    expect($stage->status)->toBe(StageStatus::Pending);
    expect($stage->config)->toBeArray();
    expect($stage->config['grand_final_reset'])->toBeTrue();
});

test('belongs to a tournament', function () {
    $tournament = Tournament::factory()->create();
    $stage = Stage::factory()->for($tournament)->create();

    expect($stage->tournament)->toBeInstanceOf(Tournament::class);
    expect($stage->tournament->id)->toBe($tournament->id);
});

test('two stages cannot share a sort_order within a tournament', function () {
    $tournament = Tournament::factory()->create();
    Stage::factory()->for($tournament)->create(['sort_order' => 0]);

    expect(fn () => Stage::factory()->for($tournament)->create(['sort_order' => 0]))
        ->toThrow(QueryException::class);
});

test('the same sort_order may be reused across different tournaments', function () {
    Stage::factory()->create(['sort_order' => 0]);
    $second = Stage::factory()->create(['sort_order' => 0]);

    expect($second->id)->toBeGreaterThan(0);
});

test('factory states set the corresponding format and config', function () {
    expect(Stage::factory()->doubleElim()->create()->format)->toBe('double_elim');
    expect(Stage::factory()->roundRobin(2, 4)->create()->config)
        ->toBe(['groups' => 2, 'group_size' => 4]);
    expect(Stage::factory()->swiss(7)->create()->config)
        ->toBe(['rounds' => 7]);
});

test('factory states set the corresponding status', function () {
    expect(Stage::factory()->inProgress()->create()->status)->toBe(StageStatus::InProgress);
    expect(Stage::factory()->completed()->create()->status)->toBe(StageStatus::Completed);
});

test('deleting a tournament cascades to its stages', function () {
    $tournament = Tournament::factory()->create();
    Stage::factory()->for($tournament)->create(['sort_order' => 0]);
    Stage::factory()->for($tournament)->create(['sort_order' => 1]);
    Stage::factory()->for($tournament)->create(['sort_order' => 2]);
    expect($tournament->stages()->count())->toBe(3);

    $tournament->forceDelete();

    expect(Stage::where('tournament_id', $tournament->id)->count())->toBe(0);
});

test('Tournament::stages() returns stages ordered by sort_order', function () {
    $tournament = Tournament::factory()->create();
    Stage::factory()->for($tournament)->create(['sort_order' => 2, 'name' => 'Third']);
    Stage::factory()->for($tournament)->create(['sort_order' => 0, 'name' => 'First']);
    Stage::factory()->for($tournament)->create(['sort_order' => 1, 'name' => 'Second']);

    $names = $tournament->stages->pluck('name')->all();
    expect($names)->toBe(['First', 'Second', 'Third']);
});

test('config field handles null cleanly', function () {
    $stage = Stage::factory()->create(['config' => null]);
    expect($stage->config)->toBeNull();
});
