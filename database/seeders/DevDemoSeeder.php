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
 * After running, the database has a single player-based single-elim
 * Rocket League tournament — `Test Tournament 1` — with one stage
 * (single_elim, 3rd-place match enabled), 6 PENDING registrations
 * waiting on the host's approval, and the tournament status set to
 * `RegistrationOpen`. The bracket is NOT built yet — that's the
 * host's next move (approve registrations → close registration →
 * seed and build).
 *
 * Skip-if-already-seeded by checking the demo tournament's slug. To
 * re-seed cleanly: `php artisan migrate:fresh && php artisan db:seed
 * --class=DevDemoSeeder` (two commands — `--class` is a db:seed flag,
 * not a migrate:fresh one).
 *
 * Self-contained — calls RolesAndPermissionsSeeder + DevUsersSeeder
 * itself so a fresh DB doesn't need a separate db:seed run.
 */
class DevDemoSeeder extends Seeder
{
    private const TOURNAMENT_NAME = 'Test Tournament 1';
    private const TOURNAMENT_SLUG = 'test-tournament-1';
    private const GAME_SLUG       = 'rocket-league';
    private const ORG_SLUG        = 'mv-esports';
    private const PLAYER_COUNT    = 6; // pending registrations from individual players

    public function run(): void
    {
        if (Tournament::where('slug', self::TOURNAMENT_SLUG)->exists()) {
            $this->command?->info('Demo state already present; skipping. Run `migrate:fresh && db:seed --class=DevDemoSeeder` to re-seed.');
            return;
        }

        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(DevUsersSeeder::class);

        DB::transaction(function () {
            $systemManager = User::where('email', 'system-manager@mvesports.test')->firstOrFail();

            $game        = $this->createGame();
            $organisation = $this->createOrganisation($systemManager);
            $hosts       = $this->createHosts($systemManager, $organisation);
            $playerUsers = $this->createPlayerUsers($game);
            $tournament  = $this->createTournament($hosts[0], $organisation, $game, $systemManager);
            $this->configureStages($tournament);
            $this->registerPlayers($tournament, $playerUsers, $game);
        });

        $this->command?->info(sprintf(
            'Demo seed complete: 1 tournament (%s), %d players, %d pending registrations, registration open.',
            self::TOURNAMENT_NAME,
            self::PLAYER_COUNT,
            self::PLAYER_COUNT,
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

    private function createTournament(
        User $host,
        Organization $organisation,
        Game $game,
        User $systemManager,
    ): Tournament {
        $hostRow = $host->tournamentHost;

        $start = Carbon::now()->addDays(14);
        $end   = $start->copy()->addDays(2);

        return Tournament::create([
            'name'                   => self::TOURNAMENT_NAME,
            'slug'                   => self::TOURNAMENT_SLUG,
            'game_id'                => $game->id,
            'host_id'                => $hostRow->id,
            'organization_id'        => $organisation->id,
            'created_by_user_id'     => $host->id,
            'approved_by_user_id'    => $systemManager->id,
            'approved_at'            => now(),
            'participant_type'       => 'player',
            'registration_type'      => 'open',
            'status'                 => TournamentStatus::RegistrationOpen,
            'description'            => 'Demo tournament — Rocket League player-based single-elim.',
            'start_date'             => $start->format('Y-m-d'),
            'end_date'               => $end->format('Y-m-d'),
            // Registration is currently open: opened a day ago, closes a week from now.
            'registration_opens_at'  => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(7),
            'stream_url'             => 'https://example.com/stream',
            'banner_url'             => null,
            'max_participants'       => 4,
        ]);
    }

    private function configureStages(Tournament $tournament): void
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
            // grand_final_reset is a double_elim-only config and isn't relevant here.
            'config'        => ['third_place_match' => true],
        ]);

        StageQualification::create([
            'source_stage_id' => null,            // entry-point from registrations
            'target_stage_id' => $stage->id,
            'rule_type'       => 'all',
            'rule_config'     => [],
        ]);
    }

    /**
     * @param  array<int, User>  $playerUsers
     */
    private function registerPlayers(Tournament $tournament, array $playerUsers, Game $game): void
    {
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
                'registered_at'         => now()->subMinutes(self::PLAYER_COUNT - $i),
            ]);
        }
    }
}
