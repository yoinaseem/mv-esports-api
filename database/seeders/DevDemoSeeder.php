<?php

namespace Database\Seeders;

use App\Enums\RegistrationStatus;
use App\Enums\StageStatus;
use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Tournament;
use App\Models\TournamentHost;
use App\Models\TournamentRegistration;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Produces a runnable demo state in one command:
 *
 *   php artisan db:seed --class=DevDemoSeeder
 *
 * After running, the database has two player-based Rocket League
 * tournaments, both `RegistrationOpen` with pending registrations:
 *
 *   1. `Test Tournament 1` — single-stage single_elim with the
 *      3rd-place match enabled, default best_of=3, 6 PENDING
 *      registrations against a max_participants of 4. Tests the
 *      approval-flow-with-overflow path (the host has more
 *      applicants than slots).
 *
 *   2. `Test Tournament 2` — two-stage flow (round-robin → double
 *      elimination), default best_of=3, 10 PENDING registrations
 *      against a max_participants of 8. Stage 1 is two groups of
 *      four; stage 2 is a 4-player DE populated by `top_n_per_group`
 *      (top 2 from each group qualifies). Tests the multi-stage
 *      seed-and-build + qualification cascade, with 2 extra pending
 *      regs so host approval/rejection still has work to do.
 *
 * Neither bracket is built yet — host approves → closes registration
 * → seed-and-builds.
 *
 * Skip-if-already-seeded by checking Test Tournament 1's slug. To
 * re-seed cleanly: `php artisan migrate:fresh && php artisan db:seed
 * --class=DevDemoSeeder` (two commands — `--class` is a db:seed flag,
 * not a migrate:fresh one).
 *
 * Self-contained — calls RolesAndPermissionsSeeder + DevUsersSeeder
 * itself so a fresh DB doesn't need a separate db:seed run.
 */
class DevDemoSeeder extends Seeder
{
    private const T1_NAME = 'Test Tournament 1';
    private const T1_SLUG = 'test-tournament-1';
    private const T1_MAX  = 4;
    private const T1_REGS = 6; // pending — overflow against the cap

    private const T2_NAME = 'Test Tournament 2';
    private const T2_SLUG = 'test-tournament-2';
    private const T2_MAX  = 8;
    private const T2_REGS = 10; // pending — overflow against the cap, host needs to reject 2

    private const GAME_SLUG    = 'rocket-league';
    private const ORG_SLUG     = 'mv-esports';
    private const PLAYER_COUNT = 10; // covers both tournaments (T1 uses 1..6, T2 uses 1..10)

    public function run(): void
    {
        if (Tournament::where('slug', self::T1_SLUG)->exists()) {
            $this->command?->info('Demo state already present; skipping. Run `migrate:fresh && db:seed --class=DevDemoSeeder` to re-seed.');
            return;
        }

        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(DevUsersSeeder::class);

        DB::transaction(function () {
            $systemManager = User::where('email', 'system-manager@mvesports.test')->firstOrFail();

            $game         = $this->createGame();
            $organisation = $this->createOrganisation($systemManager);
            $hosts        = $this->createHosts($systemManager, $organisation);
            $playerUsers  = $this->createPlayerUsers($game);

            // Tournament 1 — single-stage SE, hosted by alice.
            $t1 = $this->createTournamentOne($hosts[0], $organisation, $game, $systemManager);
            $this->configureStagesOne($t1);
            $this->registerPlayers($t1, array_slice($playerUsers, 0, self::T1_REGS), $game);

            // Tournament 2 — two-stage RR → DE, hosted by bravo.
            $t2 = $this->createTournamentTwo($hosts[1], $organisation, $game, $systemManager);
            $this->configureStagesTwo($t2);
            $this->registerPlayers($t2, array_slice($playerUsers, 0, self::T2_REGS), $game);
        });

        $this->command?->info(sprintf(
            'Demo seed complete: 2 tournaments, %d players. T1: %d pending / max %d. T2: %d pending / max %d.',
            self::PLAYER_COUNT,
            self::T1_REGS, self::T1_MAX,
            self::T2_REGS, self::T2_MAX,
        ));
    }

    private function createGame(): Game
    {
        return Game::firstOrCreate(
            ['slug' => self::GAME_SLUG],
            [
                'name'      => 'Rocket League',
                'icon_url'  => 'https://example.com/rocket-league.png',
                'is_active' => true,
            ],
        );
    }

    private function createOrganisation(User $owner): Organization
    {
        return Organization::firstOrCreate(
            ['slug' => self::ORG_SLUG],
            [
                'name'          => 'MV Esports',
                'description'   => 'The MV Esports demo organisation.',
                'owner_user_id' => $owner->id,
            ],
        );
    }

    /**
     * @return array<int, User>  the user models for hosts (their TournamentHost rows are also created)
     */
    private function createHosts(User $systemManager, Organization $organisation): array
    {
        $hostUsers = [];
        foreach (['alice', 'bravo'] as $name) {
            $email = "host-{$name}@mvesports.test";
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name'          => "Host {$name}",
                    'display_name'  => "host-{$name}",
                    'password'      => Hash::make($email),
                    'date_of_birth' => '1992-06-15',
                    'country'       => 'MV',
                ],
            );

            // Approved tournament_hosts row.
            TournamentHost::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'organization_id'     => $organisation->id,
                    'display_name'        => "host-{$name}",
                    'bio'                 => "Demo tournament host: {$name}",
                    'status'              => 'approved',
                    'approved_by_user_id' => $systemManager->id,
                    'approved_at'         => now(),
                ],
            );

            // Approved hosts get tournaments.create granted directly per the
            // post-approval flow in TournamentHostController::update.
            $user->givePermissionTo('tournaments.create');

            $hostUsers[] = $user;
        }
        return $hostUsers;
    }

    /**
     * @return array<int, User>  PLAYER_COUNT player users (with Player rows attached for $game)
     */
    private function createPlayerUsers(Game $game): array
    {
        $users = [];
        for ($i = 1; $i <= self::PLAYER_COUNT; $i++) {
            $email = sprintf('player-%02d@mvesports.test', $i);
            $user  = User::firstOrCreate(
                ['email' => $email],
                [
                    'name'          => sprintf('Player %02d', $i),
                    'display_name'  => sprintf('player-%02d', $i),
                    'password'      => Hash::make($email),
                    'date_of_birth' => '1998-03-20',
                    'country'       => 'MV',
                ],
            );

            Player::firstOrCreate(
                ['user_id' => $user->id, 'game_id' => $game->id],
                [
                    'gamertag'     => sprintf('player_%02d_tag', $i),
                    'rank_or_tier' => 'Champion II',
                ],
            );

            $users[] = $user;
        }
        return $users;
    }

    private function createTournamentOne(
        User $host,
        Organization $organisation,
        Game $game,
        User $systemManager,
    ): Tournament {
        return Tournament::create([
            'name'                   => self::T1_NAME,
            'slug'                   => self::T1_SLUG,
            'game_id'                => $game->id,
            'host_id'                => $host->tournamentHost->id,
            'organization_id'        => $organisation->id,
            'created_by_user_id'     => $host->id,
            'approved_by_user_id'    => $systemManager->id,
            'approved_at'            => now(),
            'participant_type'       => 'player',
            'registration_type'      => 'open',
            'status'                 => TournamentStatus::RegistrationOpen,
            'description'            => 'Demo tournament — Rocket League player-based single-elim with 3rd-place match.',
            'start_date'             => Carbon::now()->addDays(14)->format('Y-m-d'),
            'end_date'               => Carbon::now()->addDays(16)->format('Y-m-d'),
            'registration_opens_at'  => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(7),
            'stream_url'             => 'https://example.com/stream',
            'banner_url'             => null,
            'max_participants'       => self::T1_MAX,
        ]);
    }

    private function configureStagesOne(Tournament $tournament): void
    {
        $stage = Stage::create([
            'tournament_id' => $tournament->id,
            'name'          => 'Main Bracket',
            'format'        => 'single_elim',
            'sort_order'    => 0,
            'start_date'    => null,
            'end_date'      => null,
            'status'        => StageStatus::Pending,
            // 3rd-place match enabled (semifinal losers play off for position 3).
            // best_of=3 applies to every match the generator creates; the host
            // PATCHes per-match if they want the final at a higher bo.
            'config'        => [
                'third_place_match' => true,
                'best_of'           => 3,
            ],
        ]);

        StageQualification::create([
            'source_stage_id' => null,            // entry-point from registrations
            'target_stage_id' => $stage->id,
            'rule_type'       => 'all',
            'rule_config'     => [],
        ]);
    }

    private function createTournamentTwo(
        User $host,
        Organization $organisation,
        Game $game,
        User $systemManager,
    ): Tournament {
        return Tournament::create([
            'name'                   => self::T2_NAME,
            'slug'                   => self::T2_SLUG,
            'game_id'                => $game->id,
            'host_id'                => $host->tournamentHost->id,
            'organization_id'        => $organisation->id,
            'created_by_user_id'     => $host->id,
            'approved_by_user_id'    => $systemManager->id,
            'approved_at'            => now(),
            'participant_type'       => 'player',
            'registration_type'      => 'open',
            'status'                 => TournamentStatus::RegistrationOpen,
            'description'            => 'Demo tournament — two-stage Rocket League. Round robin (2 groups of 4) feeds top 2 from each group into a 4-player double elim.',
            'start_date'             => Carbon::now()->addDays(21)->format('Y-m-d'),
            'end_date'               => Carbon::now()->addDays(24)->format('Y-m-d'),
            'registration_opens_at'  => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(10),
            'stream_url'             => 'https://example.com/stream',
            'banner_url'             => null,
            'max_participants'       => self::T2_MAX,
        ]);
    }

    private function configureStagesTwo(Tournament $tournament): void
    {
        // Stage 1 — round robin: 2 groups of 4, bo3, draws allowed (RR
        // group stage is the natural home for them — Rocket League games
        // can end in regulation ties; the standings calculator scores a
        // draw at 1 pt and a win at 3 pts).
        $groupStage = Stage::create([
            'tournament_id' => $tournament->id,
            'name'          => 'Group Stage',
            'format'        => 'round_robin',
            'sort_order'    => 0,
            'start_date'    => null,
            'end_date'      => null,
            'status'        => StageStatus::Pending,
            'config'        => [
                'groups'      => 2,
                'group_size'  => 4,
                'best_of'     => 3,
                'allow_draws' => true,
            ],
        ]);

        // Stage 2 — double elim: 4 players (top 2 from each group), bo3.
        $playoffStage = Stage::create([
            'tournament_id' => $tournament->id,
            'name'          => 'Playoffs',
            'format'        => 'double_elim',
            'sort_order'    => 1,
            'start_date'    => null,
            'end_date'      => null,
            'status'        => StageStatus::Pending,
            'config'        => [
                'best_of' => 3,
                // No grand_final_reset — keeps the playoff short for demo purposes.
                // Host can PATCH the GF to a higher bo if they want a longer final.
            ],
        ]);

        // Stage 1 is the entry point — populate from approved registrations.
        StageQualification::create([
            'source_stage_id' => null,
            'target_stage_id' => $groupStage->id,
            'rule_type'       => 'all',
            'rule_config'     => [],
        ]);

        // Stage 2 takes the top 2 from each group of stage 1 (cross-group placement
        // produces an A1/B2 + B1/A2 bracket, which avoids same-group rematches in
        // the first round of playoffs).
        StageQualification::create([
            'source_stage_id' => $groupStage->id,
            'target_stage_id' => $playoffStage->id,
            'rule_type'       => 'top_n_per_group',
            'rule_config'     => [
                'per_group'          => 2,
                'placement_strategy' => 'cross_group',
            ],
        ]);
    }

    /**
     * @param  array<int, User>  $playerUsers
     */
    private function registerPlayers(Tournament $tournament, array $playerUsers, Game $game): void
    {
        $count = count($playerUsers);
        foreach ($playerUsers as $i => $playerUser) {
            $player = $playerUser->players()->where('game_id', $game->id)->firstOrFail();

            // Pending registrations — host hasn't approved them yet.
            // No seed assigned; the host will assign during approval (or the
            // EntryPointResolver will auto-fill when seed-and-build runs).
            TournamentRegistration::create([
                'tournament_id'         => $tournament->id,
                'participant_type'      => 'player',
                'participant_id'        => $player->id,
                'registered_by_user_id' => $playerUser->id,
                'status'                => RegistrationStatus::Pending,
                'seed'                  => null,
                'registered_at'         => now()->subMinutes($count - $i),
            ]);
        }
    }
}
