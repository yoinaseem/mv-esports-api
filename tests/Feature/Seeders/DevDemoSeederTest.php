<?php

use App\Enums\RegistrationStatus;
use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentHost;
use App\Models\TournamentRegistration;
use App\Models\User;
use Database\Seeders\DevDemoSeeder;

test('DevDemoSeeder produces a complete demo state in one run', function () {
    $this->seed(DevDemoSeeder::class);

    // Game + organisation
    expect(Game::where('slug', 'rocket-league')->exists())->toBeTrue();
    expect(Game::where('name', 'Rocket League')->exists())->toBeTrue();
    expect(Organization::where('slug', 'mv-esports')->exists())->toBeTrue();

    // Hosts (approved)
    expect(User::where('email', 'host-alice@mvesports.test')->exists())->toBeTrue();
    expect(User::where('email', 'host-bravo@mvesports.test')->exists())->toBeTrue();
    expect(TournamentHost::where('status', 'approved')->count())->toBe(2);

    // 6 player users + 6 player profiles (player-based tournament).
    expect(User::where('email', 'like', 'player-%@mvesports.test')->count())->toBe(6);
    expect(Player::count())->toBe(6);

    // No teams created — this is a player-based tournament.
    expect(Team::count())->toBe(0);

    // Tournament: Test Tournament 1, status RegistrationOpen, player-based.
    $tournament = Tournament::where('slug', 'test-tournament-1')->first();
    expect($tournament)->not->toBeNull();
    expect($tournament->name)->toBe('Test Tournament 1');
    expect($tournament->status)->toBe(TournamentStatus::RegistrationOpen);
    expect($tournament->participant_type)->toBe('player');

    // 6 PENDING registrations, no seeds assigned.
    $registrations = TournamentRegistration::where('tournament_id', $tournament->id)->get();
    expect($registrations)->toHaveCount(6);
    foreach ($registrations as $reg) {
        expect($reg->status)->toBe(RegistrationStatus::Pending);
        expect($reg->seed)->toBeNull();
        expect($reg->participant_type)->toBe('player');
    }

    // 1 stage, single_elim, third_place_match config enabled, status Pending
    // (bracket NOT built yet — host hasn't run seed-and-build).
    $stage = Stage::where('tournament_id', $tournament->id)->first();
    expect($stage)->not->toBeNull();
    expect($stage->format)->toBe('single_elim');
    expect($stage->status)->toBe(StageStatus::Pending);
    expect($stage->config)->toBe(['third_place_match' => true]);

    // No participants slotted yet (resolver hasn't run), no matches.
    expect($stage->participants()->count())->toBe(0);
    expect($stage->matches()->count())->toBe(0);
});

test('seeder is skip-if-already-seeded — second run is a no-op', function () {
    $this->seed(DevDemoSeeder::class);

    $tournamentCountBefore    = Tournament::count();
    $registrationCountBefore  = TournamentRegistration::count();

    $this->seed(DevDemoSeeder::class); // re-run

    expect(Tournament::count())->toBe($tournamentCountBefore);
    expect(TournamentRegistration::count())->toBe($registrationCountBefore);
});
