<?php

use App\Enums\MatchEventType;
use App\Enums\MatchGameStatus;
use App\Models\MatchEvent;
use App\Models\MatchGame;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public index lists games for a match sorted by game_number', function () {
    $match = TournamentMatch::factory()->create();
    MatchGame::factory()->create(['match_id' => $match->id, 'game_number' => 2]);
    MatchGame::factory()->create(['match_id' => $match->id, 'game_number' => 1]);
    MatchGame::factory()->create(['match_id' => $match->id, 'game_number' => 3]);

    $r = getJson("/api/matches/{$match->id}/games")
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect($r->json('data.0.game_number'))->toBe(1);
    expect($r->json('data.2.game_number'))->toBe(3);
});

test('store rejects unauthenticated callers', function () {
    $match = TournamentMatch::factory()->create();

    $this->postJson("/api/matches/{$match->id}/games", [
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
    ])->assertUnauthorized();
});

test('store rejects non-admin callers', function () {
    $match = TournamentMatch::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => $match->participant_a_type,
            'winner_participant_id'   => $match->participant_a_id,
        ])
        ->assertForbidden();
});

test('admin can record a game and the parent match score updates via observer', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->bestOf(3)->create(['stage_id' => $stage->id]);

    $r = $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => $match->participant_a_type,
            'winner_participant_id'   => $match->participant_a_id,
            'score_a'                 => 13,
            'score_b'                 => 7,
            'map_or_mode'             => 'haven',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.game_number', 1)
        ->assertJsonPath('data.status', 'completed');

    $match->refresh();
    expect($match->score_a)->toBe(1);
    expect($match->score_b)->toBe(0);
});

test('store emits both a game_completed and a score_update event', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->bestOf(3)->create(['stage_id' => $stage->id]);

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => $match->participant_a_type,
            'winner_participant_id'   => $match->participant_a_id,
        ])
        ->assertStatus(201);

    $events = MatchEvent::where('match_id', $match->id)->pluck('event_type');
    expect($events)->toContain(MatchEventType::GameCompleted);
    expect($events)->toContain(MatchEventType::ScoreUpdate);
});

test('store rejects a winner that is neither participant of the match', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->create(['stage_id' => $stage->id]);
    $stranger   = Team::factory()->create(['game_id' => $tournament->game_id]);

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => 'team',
            'winner_participant_id'   => $stranger->id,
        ])
        ->assertStatus(422);
});

test('store rejects a duplicate game_number within the same match', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->create(['stage_id' => $stage->id]);
    MatchGame::factory()->create(['match_id' => $match->id, 'game_number' => 1]);

    $this->actingAs($admin)
        ->postJson("/api/matches/{$match->id}/games", [
            'game_number'             => 1,
            'winner_participant_type' => $match->participant_a_type,
            'winner_participant_id'   => $match->participant_a_id,
        ])
        ->assertStatus(422);
});

test('admin can patch a game and the score recomputes', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->bestOf(3)->create(['stage_id' => $stage->id]);

    $game = MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);

    expect($match->fresh()->score_a)->toBe(1);

    $this->actingAs($admin)
        ->patchJson("/api/match-games/{$game->id}", [
            'winner_participant_type' => $match->participant_b_type,
            'winner_participant_id'   => $match->participant_b_id,
        ])
        ->assertOk();

    $match->refresh();
    expect($match->score_a)->toBe(0);
    expect($match->score_b)->toBe(1);
});

test('admin can delete a game and the score recomputes', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->bestOf(3)->create(['stage_id' => $stage->id]);

    $game = MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);

    expect($match->fresh()->score_a)->toBe(1);

    $this->actingAs($admin)
        ->deleteJson("/api/match-games/{$game->id}")
        ->assertOk();

    expect($match->fresh()->score_a)->toBe(0);
    expect(MatchGame::find($game->id))->toBeNull();
});

test('delete emits a score_update event tagged with reason game_deleted', function () {
    $admin      = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->create(['created_by_user_id' => $admin->id]);
    $stage      = Stage::factory()->for($tournament)->create();
    $match      = TournamentMatch::factory()->bestOf(3)->create(['stage_id' => $stage->id]);

    $game = MatchGame::factory()->create([
        'match_id'                => $match->id,
        'game_number'             => 1,
        'winner_participant_type' => $match->participant_a_type,
        'winner_participant_id'   => $match->participant_a_id,
        'status'                  => MatchGameStatus::Completed,
    ]);

    $this->actingAs($admin)
        ->deleteJson("/api/match-games/{$game->id}")
        ->assertOk();

    $event = MatchEvent::where('match_id', $match->id)
        ->where('event_type', MatchEventType::ScoreUpdate)
        ->latest('id')->first();

    expect($event)->not->toBeNull();
    expect($event->payload['reason'])->toBe('game_deleted');
});
