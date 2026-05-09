<?php

use App\Enums\MatchStatus;
use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Stage;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\TournamentHost;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\User;
use Database\Seeders\DevDemoSeeder;

test('DevDemoSeeder produces a complete demo state in one run', function () {
    $this->seed(DevDemoSeeder::class);

    expect(Game::where('slug', 'rocket-league')->exists())->toBeTrue();
    expect(Game::where('name', 'Rocket League')->exists())->toBeTrue();
    expect(Organization::where('slug', 'mv-esports')->exists())->toBeTrue();

    expect(User::where('email', 'host-alice@mvesports.test')->exists())->toBeTrue();
    expect(User::where('email', 'host-bravo@mvesports.test')->exists())->toBeTrue();
    expect(TournamentHost::where('status', 'approved')->count())->toBe(2);

    // 8 teams × 3 players (Rocket League is 3v3) = 24 player users.
    expect(User::where('email', 'like', 'player-%@mvesports.test')->count())->toBe(24);
    expect(Player::count())->toBe(24);

    expect(Team::count())->toBe(8);
    expect(TeamMember::count())->toBe(24);
    expect(TeamMember::where('role', 'captain')->count())->toBe(8);

    $tournament = Tournament::where('slug', 'demo-cup-2026')->first();
    expect($tournament)->not->toBeNull();
    expect($tournament->status)->toBe(TournamentStatus::InProgress);
    expect($tournament->participant_type)->toBe('team');

    expect(TournamentRegistration::where('tournament_id', $tournament->id)->count())->toBe(8);
    $seeds = TournamentRegistration::where('tournament_id', $tournament->id)
        ->orderBy('seed')
        ->pluck('seed')->toArray();
    expect($seeds)->toBe([1, 2, 3, 4, 5, 6, 7, 8]);

    $stage = Stage::where('tournament_id', $tournament->id)->first();
    expect($stage->status)->toBe(StageStatus::InProgress);
    expect($stage->participants()->count())->toBe(8);

    // 8-team SE bracket has 7 matches: 4 R1 + 2 R2 + 1 final.
    expect($stage->matches()->count())->toBe(7);
    expect($stage->matches()->where('bracket_round', 1)->count())->toBe(4);
    expect($stage->matches()->where('bracket_round', 1)->where('status', MatchStatus::Scheduled)->count())->toBe(4);
    expect($stage->matches()->where('bracket_round', 2)->where('status', MatchStatus::Pending)->count())->toBe(2);
    expect($stage->matches()->where('bracket_round', 3)->where('status', MatchStatus::Pending)->count())->toBe(1);
});

test('seeder is skip-if-already-seeded — second run is a no-op', function () {
    $this->seed(DevDemoSeeder::class);

    $tournamentCountBefore = Tournament::count();
    $teamCountBefore       = Team::count();

    $this->seed(DevDemoSeeder::class); // re-run

    expect(Tournament::count())->toBe($tournamentCountBefore);
    expect(Team::count())->toBe($teamCountBefore);
});
