<?php

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\MatchEvent;
use App\Models\Stage;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public index lists matches in a stage sorted by round then position', function () {
    $stage = Stage::factory()->create();
    TournamentMatch::factory()->create(['stage_id' => $stage->id, 'bracket_round' => 2, 'bracket_position' => 0]);
    TournamentMatch::factory()->create(['stage_id' => $stage->id, 'bracket_round' => 1, 'bracket_position' => 1]);
    TournamentMatch::factory()->create(['stage_id' => $stage->id, 'bracket_round' => 1, 'bracket_position' => 0]);

    $r = getJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}/matches")
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect($r->json('data.0.bracket_round'))->toBe(1);
    expect($r->json('data.0.bracket_position'))->toBe(0);
    expect($r->json('data.2.bracket_round'))->toBe(2);
});

test('index supports filtering by bracket_type and status', function () {
    $stage = Stage::factory()->create();
    TournamentMatch::factory()->create(['stage_id' => $stage->id, 'bracket_round' => 1, 'bracket_position' => 0, 'bracket_type' => \App\Enums\BracketType::Winners]);
    TournamentMatch::factory()->create(['stage_id' => $stage->id, 'bracket_round' => 1, 'bracket_position' => 1, 'bracket_type' => \App\Enums\BracketType::Losers]);
    TournamentMatch::factory()->completed()->create(['stage_id' => $stage->id, 'bracket_round' => 1, 'bracket_position' => 2, 'bracket_type' => \App\Enums\BracketType::Winners]);

    getJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}/matches?bracket_type=winners")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    getJson("/api/tournaments/{$stage->tournament_id}/stages/{$stage->id}/matches?status=completed")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('cross-tournament index returns 404 to prevent leaking matches across tournaments', function () {
    $stage = Stage::factory()->create();
    $other = Tournament::factory()->create();

    getJson("/api/tournaments/{$other->id}/stages/{$stage->id}/matches")
        ->assertNotFound();
});

test('show returns a match', function () {
    $match = TournamentMatch::factory()->create();

    getJson("/api/matches/{$match->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $match->id);
});

test('update rejects unauthenticated callers', function () {
    $match = TournamentMatch::factory()->create();

    $this->patchJson("/api/matches/{$match->id}", ['best_of' => 5])
        ->assertUnauthorized();
});

test('update rejects non-admin callers', function () {
    $match = TournamentMatch::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patchJson("/api/matches/{$match->id}", ['best_of' => 5])
        ->assertForbidden();
});

test('admin can patch scheduled_at and best_of', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->create(['stage_id' => $stage->id]);

    $this->actingAs($admin)
        ->patchJson("/api/matches/{$match->id}", [
            'scheduled_at' => '2026-06-01T18:00:00Z',
            'best_of'      => 5,
        ])
        ->assertOk()
        ->assertJsonPath('data.best_of', 5);
});

test('admin can call walkover from scheduled and the winner is set', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->create([
        'stage_id' => $stage->id,
        'status'   => MatchStatus::Scheduled,
    ]);

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/walkover", [
            'winner_participant_type' => $match->participant_a_type,
            'winner_participant_id'   => $match->participant_a_id,
            'reason'                  => 'no-show',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'walkover')
        ->assertJsonPath('data.winner_participant_id', $match->participant_a_id);
});

test('walkover emits a walkover_called and a status_change event', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->create([
        'stage_id' => $stage->id,
        'status'   => MatchStatus::Scheduled,
    ]);

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/walkover", [
            'winner_participant_type' => $match->participant_a_type,
            'winner_participant_id'   => $match->participant_a_id,
        ])
        ->assertOk();

    $events = MatchEvent::where('match_id', $match->id)->pluck('event_type');
    expect($events)->toContain(MatchEventType::WalkoverCalled);
    expect($events)->toContain(MatchEventType::StatusChange);
});

test('walkover rejects when the winner is not one of the participants', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->create([
        'stage_id' => $stage->id,
        'status'   => MatchStatus::Scheduled,
    ]);

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/walkover", [
            'winner_participant_type' => 'team',
            'winner_participant_id'   => 999999,
        ])
        ->assertStatus(422);
});

test('walkover rejects when the match is already in a terminal state', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->completed()->create(['stage_id' => $stage->id]);

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/walkover", [
            'winner_participant_type' => $match->participant_a_type,
            'winner_participant_id'   => $match->participant_a_id,
        ])
        ->assertStatus(422);
});
