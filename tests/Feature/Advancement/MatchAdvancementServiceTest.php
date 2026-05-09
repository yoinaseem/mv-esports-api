<?php

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\StageQualification;
use App\Services\Advancement\MatchAdvancementService;
use App\Services\Bracket\SingleEliminationGenerator;
use App\Services\Bracket\DoubleEliminationGenerator;

function buildSingleElimAndPlay(int $teams = 4): Stage
{
    $tournament = Tournament::factory()->state([
        'status' => TournamentStatus::InProgress, 'participant_type' => 'team',
    ])->create();
    $stage = Stage::factory()->for($tournament)->inProgress()->create(['format' => 'single_elim']);
    for ($i = 1; $i <= $teams; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id' => $stage->id, 'participant_type' => 'team', 'participant_id' => $team->id, 'seed' => $i,
        ]);
    }
    (new SingleEliminationGenerator())->generate($stage);
    return $stage->fresh();
}

test('completing a round-1 match propagates winner to round 2 and tries to schedule it', function () {
    $stage = buildSingleElimAndPlay(4);
    $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
    $r2 = $stage->matches()->where('bracket_round', 2)->orderBy('bracket_position')->first();

    // Decide R1 match 0.
    $r1[0]->update([
        'winner_participant_type' => $r1[0]->participant_a_type,
        'winner_participant_id'   => $r1[0]->participant_a_id,
        'status'                  => MatchStatus::Completed,
        'completed_at'            => now(),
    ]);

    app(MatchAdvancementService::class)->advance($r1[0]->fresh());

    $r2->refresh();
    // Slot a should be populated.
    expect($r2->participant_a_id)->toBe($r1[0]->participant_a_id);
    // Slot b still empty.
    expect($r2->participant_b_id)->toBeNull();
    // Status stays Pending.
    expect($r2->status)->toBe(MatchStatus::Pending);
});

test('completing both round-1 matches transitions round 2 to Scheduled', function () {
    $stage = buildSingleElimAndPlay(4);
    $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
    $r2 = $stage->matches()->where('bracket_round', 2)->first();

    foreach ($r1 as $m) {
        $m->update([
            'winner_participant_type' => $m->participant_a_type,
            'winner_participant_id'   => $m->participant_a_id,
            'status'                  => MatchStatus::Completed,
            'completed_at'            => now(),
        ]);
        app(MatchAdvancementService::class)->advance($m->fresh());
    }

    expect($r2->fresh()->status)->toBe(MatchStatus::Scheduled);
    expect($r2->fresh()->participant_a_id)->not->toBeNull();
    expect($r2->fresh()->participant_b_id)->not->toBeNull();
});

test('full SE cascade: completing the final transitions stage and tournament to Completed', function () {
    $stage = buildSingleElimAndPlay(4);

    // Decide R1
    $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->get();
    foreach ($r1 as $m) {
        $m->update([
            'winner_participant_type' => $m->participant_a_type,
            'winner_participant_id'   => $m->participant_a_id,
            'status'                  => MatchStatus::Completed,
            'completed_at'            => now(),
        ]);
        app(MatchAdvancementService::class)->advance($m->fresh());
    }

    // Decide final
    $final = $stage->matches()->where('bracket_round', 2)->first()->fresh();
    $final->update([
        'winner_participant_type' => $final->participant_a_type,
        'winner_participant_id'   => $final->participant_a_id,
        'status'                  => MatchStatus::Completed,
        'completed_at'            => now(),
    ]);
    app(MatchAdvancementService::class)->advance($final->fresh());

    expect($stage->fresh()->status)->toBe(StageStatus::Completed);
    expect($stage->tournament->fresh()->status)->toBe(TournamentStatus::Completed);
});

test('GF reset is cancelled when the W-bracket finalist wins GF1', function () {
    $tournament = Tournament::factory()->state([
        'status' => TournamentStatus::InProgress, 'participant_type' => 'team',
    ])->create();
    $stage = Stage::factory()->for($tournament)->inProgress()->doubleElim()->create();
    for ($i = 1; $i <= 4; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id' => $stage->id, 'participant_type' => 'team', 'participant_id' => $team->id, 'seed' => $i,
        ]);
    }
    (new DoubleEliminationGenerator())->generate($stage);

    $stage = $stage->fresh();
    $wFinal = $stage->matches()->where('bracket_type', BracketType::Winners)->orderByDesc('bracket_round')->first();
    $reset  = $stage->matches()->where('bracket_type', BracketType::GrandFinal)->where('bracket_round', 2)->first();
    $gf     = $stage->matches()->where('bracket_type', BracketType::GrandFinal)->where('bracket_round', 1)->first();

    // Force the W finalist into the GF1 winner.
    $wFinal->update([
        'winner_participant_type' => 'team',
        'winner_participant_id'   => $wFinal->participant_a_id ?? 1,
        'status'                  => MatchStatus::Completed,
        'completed_at'            => now(),
    ]);
    $gf->update([
        'participant_a_type' => 'team',
        'participant_a_id'   => $wFinal->winner_participant_id,
        'participant_b_type' => 'team',
        'participant_b_id'   => $wFinal->winner_participant_id,  // doesn't matter; same team for the test
        'winner_participant_type' => 'team',
        'winner_participant_id'   => $wFinal->winner_participant_id,
        'status'                  => MatchStatus::Completed,
        'completed_at'            => now(),
    ]);

    app(MatchAdvancementService::class)->advance($gf->fresh());

    // Reset was Conditional → Cancelled.
    expect($reset->fresh()->status)->toBe(MatchStatus::Cancelled);
});

test('GF reset is activated and Pending when the L-bracket finalist wins GF1', function () {
    $tournament = Tournament::factory()->state([
        'status' => TournamentStatus::InProgress, 'participant_type' => 'team',
    ])->create();
    $stage = Stage::factory()->for($tournament)->inProgress()->doubleElim()->create();
    for ($i = 1; $i <= 4; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        StageParticipant::factory()->create([
            'stage_id' => $stage->id, 'participant_type' => 'team', 'participant_id' => $team->id, 'seed' => $i,
        ]);
    }
    (new DoubleEliminationGenerator())->generate($stage);
    $stage = $stage->fresh();

    $wFinal = $stage->matches()->where('bracket_type', BracketType::Winners)->orderByDesc('bracket_round')->first();
    $reset  = $stage->matches()->where('bracket_type', BracketType::GrandFinal)->where('bracket_round', 2)->first();
    $gf     = $stage->matches()->where('bracket_type', BracketType::GrandFinal)->where('bracket_round', 1)->first();

    // W finalist wins W final.
    $wFinalWinner = Team::factory()->create(['game_id' => $tournament->game_id]);
    $lFinalWinner = Team::factory()->create(['game_id' => $tournament->game_id]);
    $wFinal->update([
        'winner_participant_type' => 'team',
        'winner_participant_id'   => $wFinalWinner->id,
        'status'                  => MatchStatus::Completed,
    ]);

    // GF1: W-finalist (slot a) vs L-finalist (slot b); L wins.
    $gf->update([
        'participant_a_type' => 'team', 'participant_a_id' => $wFinalWinner->id,
        'participant_b_type' => 'team', 'participant_b_id' => $lFinalWinner->id,
        'winner_participant_type' => 'team',
        'winner_participant_id'   => $lFinalWinner->id, // L wins
        'status'                  => MatchStatus::Completed,
        'completed_at'            => now(),
    ]);

    app(MatchAdvancementService::class)->advance($gf->fresh());

    // Reset transitioned out of Conditional, slots populated, status Scheduled.
    $reset->refresh();
    expect($reset->status)->toBe(MatchStatus::Scheduled);
    expect($reset->participant_a_id)->toBe($wFinalWinner->id);
    expect($reset->participant_b_id)->toBe($lFinalWinner->id);
});

test('transaction rolls back if any step in the cascade throws', function () {
    $stage = buildSingleElimAndPlay(4);
    $r1 = $stage->matches()->where('bracket_round', 1)->orderBy('bracket_position')->first();

    // Manually corrupt the FK target so propagation throws on slot conflict.
    $r2 = $stage->matches()->where('bracket_round', 2)->first();
    $stranger = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
    $r2->update([
        'participant_a_type' => 'team',
        'participant_a_id'   => $stranger->id,
    ]);

    $r1->update([
        'winner_participant_type' => $r1->participant_a_type,
        'winner_participant_id'   => $r1->participant_a_id,
        'status'                  => MatchStatus::Completed,
        'completed_at'            => now(),
    ]);

    expect(fn () => app(MatchAdvancementService::class)->advance($r1->fresh()))
        ->toThrow(\DomainException::class);

    // No partial state — the stranger slot should still be the stranger,
    // not overwritten with the round-1 winner.
    expect($r2->fresh()->participant_a_id)->toBe($stranger->id);
});
