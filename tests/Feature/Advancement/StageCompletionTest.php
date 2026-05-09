<?php

use App\Enums\MatchStatus;
use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Services\Advancement\StageCompletion;

test('does nothing when any match in the stage is non-terminal', function () {
    $stage = Stage::factory()->inProgress()->create();
    TournamentMatch::factory()->create([
        'stage_id' => $stage->id, 'status' => MatchStatus::Completed,
    ]);
    TournamentMatch::factory()->create([
        'stage_id' => $stage->id, 'status' => MatchStatus::Scheduled,
    ]);

    app(StageCompletion::class)->checkAndClose($stage);

    expect($stage->fresh()->status)->toBe(StageStatus::InProgress);
});

test('transitions stage to Completed when all matches are terminal', function () {
    $stage = Stage::factory()->inProgress()->create();
    TournamentMatch::factory()->count(3)->create([
        'stage_id' => $stage->id, 'status' => MatchStatus::Completed,
    ]);

    app(StageCompletion::class)->checkAndClose($stage);

    expect($stage->fresh()->status)->toBe(StageStatus::Completed);
});

test('treats walkover and cancelled as terminal too', function () {
    $stage = Stage::factory()->inProgress()->create();
    TournamentMatch::factory()->create(['stage_id' => $stage->id, 'status' => MatchStatus::Completed]);
    TournamentMatch::factory()->create(['stage_id' => $stage->id, 'status' => MatchStatus::Walkover]);
    TournamentMatch::factory()->create(['stage_id' => $stage->id, 'status' => MatchStatus::Cancelled]);

    app(StageCompletion::class)->checkAndClose($stage);

    expect($stage->fresh()->status)->toBe(StageStatus::Completed);
});

test('cascades: completion populates downstream stage and generates its bracket', function () {
    $tournament = Tournament::factory()->state(['status' => TournamentStatus::InProgress])->create();
    $upstream   = Stage::factory()->for($tournament)->inProgress()->create([
        'format' => 'round_robin', 'sort_order' => 0,
        'config' => ['groups' => 1, 'group_size' => 4],
    ]);
    $downstream = Stage::factory()->for($tournament)->create([
        'format' => 'single_elim', 'sort_order' => 1,
    ]);

    // Set up upstream RR with completed matches.
    $teams = [];
    for ($i = 1; $i <= 4; $i++) {
        $teams[$i] = Team::factory()->create(['game_id' => $tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id'         => $upstream->id,
            'participant_type' => 'team',
            'participant_id'   => $teams[$i]->id,
            'seed'             => $i,
            'group_number'     => 1,
            'final_position'   => $i,
        ]);
    }
    // Add completed matches so the "all matches terminal" check passes.
    $pairs = [[1, 2], [1, 3], [1, 4], [2, 3], [2, 4], [3, 4]];
    foreach ($pairs as $i => [$a, $b]) {
        TournamentMatch::factory()->create([
            'stage_id'                => $upstream->id,
            'bracket_round'           => 1,
            'bracket_position'        => $i,
            'bracket_type'            => \App\Enums\BracketType::Group,
            'group_number'            => 1,
            'participant_a_type'      => 'team',
            'participant_a_id'        => $teams[$a]->id,
            'participant_b_type'      => 'team',
            'participant_b_id'        => $teams[$b]->id,
            'winner_participant_type' => 'team',
            'winner_participant_id'   => $teams[$a]->id, // lower seed always wins
            'score_a' => 1, 'score_b' => 0,
            'status' => MatchStatus::Completed,
        ]);
    }

    // Top 2 of group qualify into downstream.
    StageQualification::factory()->topNPerGroup(perGroup: 2)->create([
        'source_stage_id' => $upstream->id,
        'target_stage_id' => $downstream->id,
    ]);

    app(StageCompletion::class)->checkAndClose($upstream);

    // Upstream completed.
    expect($upstream->fresh()->status)->toBe(StageStatus::Completed);
    // Downstream populated and built.
    expect($downstream->fresh()->participants()->count())->toBe(2);
    expect($downstream->fresh()->matches()->count())->toBe(1); // 2-team SE has 1 match
    expect($downstream->fresh()->status)->toBe(StageStatus::InProgress);
});
