<?php

use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\Bracket\SingleEliminationGenerator;

test('walkover endpoint propagates the surviving participant to round 2', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create([
        'created_by_user_id' => $admin->id,
        'participant_type'   => 'team',
    ]);
    $stage = Stage::factory()->for($tournament)->inProgress()->create(['format' => 'single_elim']);
    for ($i = 1; $i <= 4; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id' => $stage->id, 'participant_type' => 'team', 'participant_id' => $team->id, 'seed' => $i,
        ]);
    }
    (new SingleEliminationGenerator())->generate($stage);

    $r1Match = $stage->fresh()->matches()->where('bracket_round', 1)->where('bracket_position', 0)->first();
    $r2Match = $stage->matches()->where('bracket_round', 2)->first();

    $this->actingAs($admin)
        ->postJson("/api/matches/{$r1Match->id}/walkover", [
            'winner_participant_type' => $r1Match->participant_a_type,
            'winner_participant_id'   => $r1Match->participant_a_id,
            'reason'                  => 'opponent no-show',
        ])
        ->assertOk();

    // R2 slot a should have been populated by the cascade.
    $r2Match->refresh();
    expect($r2Match->participant_a_id)->toBe($r1Match->participant_a_id);
});
