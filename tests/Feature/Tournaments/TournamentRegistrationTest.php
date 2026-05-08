<?php

use App\Enums\RegistrationStatus;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentRegistration;

test('a registration casts status as a backed enum', function () {
    $reg = TournamentRegistration::factory()->create();

    expect($reg->status)->toBeInstanceOf(RegistrationStatus::class);
    expect($reg->status)->toBe(RegistrationStatus::Pending);
});

test('participant_type stores the morph alias, not FQCN', function () {
    $reg = TournamentRegistration::factory()->create();

    expect($reg->participant_type)->toBe('team');
});

test('participant relation resolves to the morphed model (Team)', function () {
    $reg = TournamentRegistration::factory()->create();

    expect($reg->participant)->toBeInstanceOf(Team::class);
});

test('participant relation resolves to a Player when participant_type is player', function () {
    $tournament = Tournament::factory()->playerType()->registrationOpen()->create();
    $player     = Player::factory()->create(['game_id' => $tournament->game_id]);

    $reg = TournamentRegistration::factory()->create([
        'tournament_id'    => $tournament->id,
        'participant_type' => 'player',
        'participant_id'   => $player->id,
    ]);

    expect($reg->participant)->toBeInstanceOf(Player::class);
    expect($reg->participant->id)->toBe($player->id);
});

test('Team::tournamentRegistrations and Player::tournamentRegistrations resolve', function () {
    $reg = TournamentRegistration::factory()->create();
    $team = $reg->participant; // Team

    expect($team->tournamentRegistrations()->count())->toBe(1);
    expect($team->tournamentRegistrations->first()->id)->toBe($reg->id);
});

test('deleting a tournament cascades to its registrations', function () {
    $tournament = Tournament::factory()->registrationOpen()->create();
    $team       = Team::factory()->create(['game_id' => $tournament->game_id]);
    TournamentRegistration::factory()->create([
        'tournament_id'    => $tournament->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]);

    $tournament->forceDelete();

    expect(TournamentRegistration::where('tournament_id', $tournament->id)->count())->toBe(0);
});

test('all factory state methods set the corresponding status', function () {
    expect(TournamentRegistration::factory()->approved()->create()->status)->toBe(RegistrationStatus::Approved);
    expect(TournamentRegistration::factory()->rejected()->create()->status)->toBe(RegistrationStatus::Rejected);
    expect(TournamentRegistration::factory()->withdrawn()->create()->status)->toBe(RegistrationStatus::Withdrawn);
});

// ---------------------------------------------------------------------------
// Partial unique indexes (DB-level safety net for races, B2 + B3)
// ---------------------------------------------------------------------------

test('participant_active_unique index prevents two active registrations of the same participant', function () {
    $tournament = Tournament::factory()->registrationOpen()->create();
    $team       = Team::factory()->create(['game_id' => $tournament->game_id]);

    TournamentRegistration::factory()->create([
        'tournament_id'    => $tournament->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]);

    // Same (tournament, participant) with non-terminal status → DB rejects.
    expect(fn () => TournamentRegistration::factory()->create([
        'tournament_id'    => $tournament->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

test('participant_active_unique allows re-registration after withdrawal', function () {
    $tournament = Tournament::factory()->registrationOpen()->create();
    $team       = Team::factory()->create(['game_id' => $tournament->game_id]);

    // First registration is withdrawn.
    TournamentRegistration::factory()->withdrawn()->create([
        'tournament_id'    => $tournament->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]);

    // Second registration of the same participant — partial index excludes
    // withdrawn rows, so this should succeed.
    $second = TournamentRegistration::factory()->create([
        'tournament_id'    => $tournament->id,
        'participant_type' => 'team',
        'participant_id'   => $team->id,
    ]);

    expect($second->id)->toBeGreaterThan(0);
});

test('user_active_unique index prevents two active registrations by the same user (different participants)', function () {
    $user       = \App\Models\User::factory()->create();
    $tournament = Tournament::factory()->registrationOpen()->create();
    $teamA      = Team::factory()->create(['game_id' => $tournament->game_id]);
    $teamB      = Team::factory()->create(['game_id' => $tournament->game_id]);

    TournamentRegistration::factory()->create([
        'tournament_id'         => $tournament->id,
        'participant_type'      => 'team',
        'participant_id'        => $teamA->id,
        'registered_by_user_id' => $user->id,
    ]);

    // Same user, different team — DB-level rejection.
    expect(fn () => TournamentRegistration::factory()->create([
        'tournament_id'         => $tournament->id,
        'participant_type'      => 'team',
        'participant_id'        => $teamB->id,
        'registered_by_user_id' => $user->id,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
