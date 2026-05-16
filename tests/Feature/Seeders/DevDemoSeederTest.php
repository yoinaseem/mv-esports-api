<?php

use App\Enums\RegistrationStatus;
use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Stage;
use App\Models\StageQualification;
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
    expect(Organization::where('slug', 'mv-esports')->exists())->toBeTrue();

    // Hosts (approved)
    expect(User::where('email', 'host-alice@mvesports.test')->exists())->toBeTrue();
    expect(User::where('email', 'host-bravo@mvesports.test')->exists())->toBeTrue();
    expect(TournamentHost::where('status', 'approved')->count())->toBe(2);

    // 10 player users / Player rows — covers both tournaments (T1 takes 1..6, T2 takes 1..10).
    expect(User::where('email', 'like', 'player-%@mvesports.test')->count())->toBe(10);
    expect(Player::count())->toBe(10);

    // No teams created — both tournaments are player-based.
    expect(Team::count())->toBe(0);

    // Two tournaments, both RegistrationOpen.
    expect(Tournament::count())->toBe(2);
});

test('Test Tournament 1 — single-stage SE with 3rd place + bo3, 6 pending against max 4', function () {
    $this->seed(DevDemoSeeder::class);

    $t = Tournament::where('slug', 'test-tournament-1')->first();
    expect($t)->not->toBeNull();
    expect($t->name)->toBe('Test Tournament 1');
    expect($t->status)->toBe(TournamentStatus::RegistrationOpen);
    expect($t->participant_type)->toBe('player');
    expect($t->max_participants)->toBe(4);

    // 6 pending registrations, no seeds.
    $regs = TournamentRegistration::where('tournament_id', $t->id)->get();
    expect($regs)->toHaveCount(6);
    foreach ($regs as $reg) {
        expect($reg->status)->toBe(RegistrationStatus::Pending);
        expect($reg->seed)->toBeNull();
        expect($reg->participant_type)->toBe('player');
    }

    // Single stage: SE, 3rd-place + bo3.
    $stages = Stage::where('tournament_id', $t->id)->orderBy('sort_order')->get();
    expect($stages)->toHaveCount(1);
    $stage = $stages[0];
    expect($stage->format)->toBe('single_elim');
    expect($stage->status)->toBe(StageStatus::Pending);
    expect($stage->config)->toBe(['third_place_match' => true, 'best_of' => 3]);

    // Entry-point qualification.
    $entry = StageQualification::where('target_stage_id', $stage->id)->first();
    expect($entry->source_stage_id)->toBeNull();
    expect($entry->rule_type)->toBe('all');

    expect($stage->participants()->count())->toBe(0);
    expect($stage->matches()->count())->toBe(0);
});

test('Test Tournament 2 — RR groups feeding into DE playoffs, bo3 throughout, 10 pending against max 8', function () {
    $this->seed(DevDemoSeeder::class);

    $t = Tournament::where('slug', 'test-tournament-2')->first();
    expect($t)->not->toBeNull();
    expect($t->name)->toBe('Test Tournament 2');
    expect($t->status)->toBe(TournamentStatus::RegistrationOpen);
    expect($t->participant_type)->toBe('player');
    expect($t->max_participants)->toBe(8);

    // 10 pending registrations, no seeds — host approves 8 and rejects 2.
    $regs = TournamentRegistration::where('tournament_id', $t->id)->get();
    expect($regs)->toHaveCount(10);
    foreach ($regs as $reg) {
        expect($reg->status)->toBe(RegistrationStatus::Pending);
        expect($reg->seed)->toBeNull();
    }

    // Two stages: RR (groups=2, group_size=4, bo3) → DE (bo3).
    $stages = Stage::where('tournament_id', $t->id)->orderBy('sort_order')->get();
    expect($stages)->toHaveCount(2);

    $groupStage = $stages[0];
    expect($groupStage->format)->toBe('round_robin');
    expect($groupStage->status)->toBe(StageStatus::Pending);
    expect($groupStage->config)->toBe(['groups' => 2, 'group_size' => 4, 'best_of' => 3, 'allow_draws' => true]);

    $playoffStage = $stages[1];
    expect($playoffStage->format)->toBe('double_elim');
    expect($playoffStage->status)->toBe(StageStatus::Pending);
    expect($playoffStage->config)->toBe(['best_of' => 3]);

    // Qualification graph: null → group (rule=all); group → playoff (top_n_per_group, n=2 cross_group).
    $entry = StageQualification::where('target_stage_id', $groupStage->id)->first();
    expect($entry->source_stage_id)->toBeNull();
    expect($entry->rule_type)->toBe('all');

    $playoffQual = StageQualification::where('target_stage_id', $playoffStage->id)->first();
    expect($playoffQual->source_stage_id)->toBe($groupStage->id);
    expect($playoffQual->rule_type)->toBe('top_n_per_group');
    expect($playoffQual->rule_config)->toBe(['per_group' => 2, 'placement_strategy' => 'cross_group']);
});

test('seeder is skip-if-already-seeded — second run is a no-op', function () {
    $this->seed(DevDemoSeeder::class);

    $tournamentCountBefore   = Tournament::count();
    $registrationCountBefore = TournamentRegistration::count();

    $this->seed(DevDemoSeeder::class); // re-run

    expect(Tournament::count())->toBe($tournamentCountBefore);
    expect(TournamentRegistration::count())->toBe($registrationCountBefore);
});
