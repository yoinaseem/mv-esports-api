<?php

use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\Advancement\QualificationResolver;

function buildResolverStages(int $upstreamCount): array
{
    $tournament = Tournament::factory()->create();
    $upstream   = Stage::factory()->for($tournament)->create(['sort_order' => 0]);
    $downstream = Stage::factory()->for($tournament)->create(['sort_order' => 1]);

    for ($i = 1; $i <= $upstreamCount; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id'         => $upstream->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $i,
            'final_position'   => $i,
        ]);
    }

    return [$tournament, $upstream, $downstream];
}

test('rule_type=all copies every upstream participant to downstream', function () {
    [, $upstream, $downstream] = buildResolverStages(8);
    StageQualification::factory()->all()->create([
        'source_stage_id' => $upstream->id,
        'target_stage_id' => $downstream->id,
    ]);

    $newlyPopulated = app(QualificationResolver::class)->resolveDownstream($upstream);

    expect($newlyPopulated)->toHaveCount(1);
    expect($downstream->participants()->count())->toBe(8);
    // Seeds reassigned 1..8 in final_position order.
    $seeds = $downstream->participants()->orderBy('seed')->pluck('seed')->toArray();
    expect($seeds)->toBe([1, 2, 3, 4, 5, 6, 7, 8]);
});

test('rule_type=top_n takes top N by final_position', function () {
    [, $upstream, $downstream] = buildResolverStages(8);
    StageQualification::factory()->create([
        'source_stage_id' => $upstream->id,
        'target_stage_id' => $downstream->id,
        'rule_type'       => 'top_n',
        'rule_config'     => ['n' => 4],
    ]);

    app(QualificationResolver::class)->resolveDownstream($upstream);

    expect($downstream->participants()->count())->toBe(4);
});

test('rule_type=top_n includes all tied participants at the boundary', function () {
    [, $upstream, $downstream] = buildResolverStages(8);
    // Make positions 4 and 5 tied at 4.
    $upstream->participants()->where('seed', 5)->update(['final_position' => 4]);

    StageQualification::factory()->create([
        'source_stage_id' => $upstream->id,
        'target_stage_id' => $downstream->id,
        'rule_type'       => 'top_n',
        'rule_config'     => ['n' => 4],
    ]);

    app(QualificationResolver::class)->resolveDownstream($upstream);

    // 5 qualifiers (positions 1, 2, 3, 4, 4) instead of strict 4.
    expect($downstream->participants()->count())->toBe(5);
});

test('rule_type=manual is skipped — downstream stays empty', function () {
    [, $upstream, $downstream] = buildResolverStages(4);
    StageQualification::factory()->manual()->create([
        'source_stage_id' => $upstream->id,
        'target_stage_id' => $downstream->id,
    ]);

    $newlyPopulated = app(QualificationResolver::class)->resolveDownstream($upstream);

    expect($newlyPopulated)->toHaveCount(0);
    expect($downstream->participants()->count())->toBe(0);
});

test('top_n_per_group applies cross-group placement', function () {
    $tournament = Tournament::factory()->create();
    $upstream   = Stage::factory()->for($tournament)->create([
        'format' => 'round_robin', 'sort_order' => 0,
        'config' => ['groups' => 2, 'group_size' => 4],
    ]);
    $downstream = Stage::factory()->for($tournament)->create(['sort_order' => 1]);

    // Group 1: 1A=Alpha, 2A=Bravo, 3A, 4A
    // Group 2: 1B=Echo, 2B=Foxtrot, 3B, 4B
    $teams = [];
    for ($i = 1; $i <= 8; $i++) {
        $teams[$i] = Team::factory()->create(['game_id' => $tournament->game_id]);
    }
    // Group 1
    StageParticipant::factory()->create(['stage_id' => $upstream->id, 'participant_type' => 'team', 'participant_id' => $teams[1]->id, 'seed' => 1, 'group_number' => 1, 'final_position' => 1]);
    StageParticipant::factory()->create(['stage_id' => $upstream->id, 'participant_type' => 'team', 'participant_id' => $teams[2]->id, 'seed' => 2, 'group_number' => 1, 'final_position' => 2]);
    StageParticipant::factory()->create(['stage_id' => $upstream->id, 'participant_type' => 'team', 'participant_id' => $teams[3]->id, 'seed' => 3, 'group_number' => 1, 'final_position' => 3]);
    StageParticipant::factory()->create(['stage_id' => $upstream->id, 'participant_type' => 'team', 'participant_id' => $teams[4]->id, 'seed' => 4, 'group_number' => 1, 'final_position' => 4]);
    // Group 2
    StageParticipant::factory()->create(['stage_id' => $upstream->id, 'participant_type' => 'team', 'participant_id' => $teams[5]->id, 'seed' => 5, 'group_number' => 2, 'final_position' => 1]);
    StageParticipant::factory()->create(['stage_id' => $upstream->id, 'participant_type' => 'team', 'participant_id' => $teams[6]->id, 'seed' => 6, 'group_number' => 2, 'final_position' => 2]);
    StageParticipant::factory()->create(['stage_id' => $upstream->id, 'participant_type' => 'team', 'participant_id' => $teams[7]->id, 'seed' => 7, 'group_number' => 2, 'final_position' => 3]);
    StageParticipant::factory()->create(['stage_id' => $upstream->id, 'participant_type' => 'team', 'participant_id' => $teams[8]->id, 'seed' => 8, 'group_number' => 2, 'final_position' => 4]);

    StageQualification::factory()->topNPerGroup(perGroup: 2)->create([
        'source_stage_id' => $upstream->id,
        'target_stage_id' => $downstream->id,
    ]);

    app(QualificationResolver::class)->resolveDownstream($upstream);

    // Expected cross-group seed assignment:
    //   Tier 1 (group winners): Alpha → seed 1, Echo → seed 2
    //   Tier 2 (runners-up):    Bravo → seed 3, Foxtrot → seed 4
    $bySeed = $downstream->participants()->orderBy('seed')->get()->keyBy('seed');
    expect($bySeed[1]->participant_id)->toBe($teams[1]->id);  // 1A=Alpha
    expect($bySeed[2]->participant_id)->toBe($teams[5]->id);  // 1B=Echo
    expect($bySeed[3]->participant_id)->toBe($teams[2]->id);  // 2A=Bravo
    expect($bySeed[4]->participant_id)->toBe($teams[6]->id);  // 2B=Foxtrot
});

test('does not double-populate if downstream already has participants', function () {
    [, $upstream, $downstream] = buildResolverStages(4);
    StageQualification::factory()->all()->create([
        'source_stage_id' => $upstream->id,
        'target_stage_id' => $downstream->id,
    ]);

    // Pre-populate downstream.
    $existingTeam = Team::factory()->create(['game_id' => $upstream->tournament->game_id]);
    StageParticipant::factory()->create([
        'stage_id'         => $downstream->id,
        'participant_type' => 'team',
        'participant_id'   => $existingTeam->id,
        'seed'             => 1,
    ]);

    $newlyPopulated = app(QualificationResolver::class)->resolveDownstream($upstream);

    expect($newlyPopulated)->toHaveCount(0);
    expect($downstream->participants()->count())->toBe(1); // unchanged
});

test('skips when upstream has any participant with null final_position (cancelled-final guard)', function () {
    [, $upstream, $downstream] = buildResolverStages(4);
    StageQualification::factory()->all()->create([
        'source_stage_id' => $upstream->id,
        'target_stage_id' => $downstream->id,
    ]);

    // Force one participant's final_position to null (simulates a stage where
    // standings couldn't be computed because the deciding match was cancelled).
    $upstream->participants()->where('seed', 4)->update(['final_position' => null]);

    $newlyPopulated = app(QualificationResolver::class)->resolveDownstream($upstream);

    expect($newlyPopulated)->toHaveCount(0);
    expect($downstream->participants()->count())->toBe(0);
});

test('returns no newly-populated stages when upstream has no downstream qualifications', function () {
    [, $upstream] = buildResolverStages(4);

    $newlyPopulated = app(QualificationResolver::class)->resolveDownstream($upstream);

    expect($newlyPopulated)->toHaveCount(0);
});
