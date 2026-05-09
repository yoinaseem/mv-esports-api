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
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\TournamentHost;
use App\Models\TournamentRegistration;
use App\Models\User;
use App\Services\Bracket\SeedAndBuildService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Produces a runnable demo state in one command:
 *
 *   php artisan db:seed --class=DevDemoSeeder
 *
 * After running, the database has a single 8-team team-based single-elim
 * Rocket League tournament, fully built and ready to play. A developer
 * can sign in as host-alice@mvesports.test (password = email), POST games
 * to round-1 matches, and watch the cascade fire.
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
    private const TOURNAMENT_SLUG = 'demo-cup-2026';
    private const GAME_SLUG       = 'rocket-league';
    private const ORG_SLUG        = 'mv-esports';
    private const TEAM_NAMES      = ['Aces', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel'];
    private const TEAM_SIZE       = 3; // Rocket League is 3v3 in standard competitive
    private const TEAM_COUNT      = 8;

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
            $teams       = $this->createTeams($organisation, $game, $playerUsers);
            $tournament  = $this->createTournament($hosts[0], $organisation, $game, $systemManager);
            $this->registerTeams($tournament, $teams);
            $this->configureStages($tournament);
            $this->seedAndBuild($tournament);
        });

        $this->command?->info(sprintf(
            'Demo seed complete: 1 tournament, %d teams, %d players, bracket built.',
            self::TEAM_COUNT,
            self::TEAM_COUNT * self::TEAM_SIZE,
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
     * @return array<int, User>  TEAM_COUNT × TEAM_SIZE player users (with Player rows attached for $game)
     */
    private function createPlayerUsers(Game $game): array
    {
        $count = self::TEAM_COUNT * self::TEAM_SIZE;
        $users = [];
        for ($i = 1; $i <= $count; $i++) {
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

    /**
     * @param  array<int, User>  $playerUsers
     * @return array<int, Team>  TEAM_COUNT teams of TEAM_SIZE
     */
    private function createTeams(Organization $organisation, Game $game, array $playerUsers): array
    {
        $teams = [];
        foreach (self::TEAM_NAMES as $index => $teamName) {
            // TEAM_SIZE members per team, drawn from $playerUsers in order.
            $members = array_slice($playerUsers, $index * self::TEAM_SIZE, self::TEAM_SIZE);
            $captain = $members[0]->players()->where('game_id', $game->id)->firstOrFail();

            $team = Team::firstOrCreate(
                ['name' => "Team {$teamName}"],
                [
                    'organization_id'      => $organisation->id,
                    'game_id'              => $game->id,
                    'tag'                  => strtoupper(substr($teamName, 0, 3)),
                    'logo_url'             => null,
                    'created_by_player_id' => $captain->id,
                ],
            );

            foreach ($members as $i => $memberUser) {
                $playerForGame = $memberUser->players()->where('game_id', $game->id)->firstOrFail();
                TeamMember::firstOrCreate(
                    ['team_id' => $team->id, 'player_id' => $playerForGame->id],
                    [
                        'role'      => $i === 0 ? 'captain' : 'member',
                        'joined_at' => now(),
                        'left_at'   => null,
                    ],
                );
            }

            $teams[] = $team;
        }
        return $teams;
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
            'name'                   => 'Demo Cup 2026',
            'slug'                   => self::TOURNAMENT_SLUG,
            'game_id'                => $game->id,
            'host_id'                => $hostRow->id,
            'organization_id'        => $organisation->id,
            'created_by_user_id'     => $host->id,
            'approved_by_user_id'    => $systemManager->id,
            'approved_at'            => now(),
            'participant_type'       => 'team',
            'registration_type'      => 'open',
            'status'                 => TournamentStatus::RegistrationClosed,
            'description'            => 'Demo tournament — 8-team Rocket League single-elim.',
            'start_date'             => $start->format('Y-m-d'),
            'end_date'               => $end->format('Y-m-d'),
            'registration_opens_at'  => Carbon::now()->subDays(7),
            'registration_closes_at' => Carbon::now()->subDay(),
            'stream_url'             => 'https://example.com/stream',
            'banner_url'             => null,
            'max_participants'       => 8,
        ]);
    }

    /**
     * @param  array<int, Team>  $teams
     */
    private function registerTeams(Tournament $tournament, array $teams): void
    {
        foreach ($teams as $i => $team) {
            $captainMember = $team->members()->where('role', 'captain')->firstOrFail();
            $captainUser   = $captainMember->player->user;

            TournamentRegistration::create([
                'tournament_id'         => $tournament->id,
                'participant_type'      => 'team',
                'participant_id'        => $team->id,
                'registered_by_user_id' => $captainUser->id,
                'status'                => RegistrationStatus::Approved,
                'seed'                  => $i + 1,
                'registered_at'         => now(),
            ]);
        }
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
            'config'        => null,
        ]);

        StageQualification::create([
            'source_stage_id' => null,            // entry-point from registrations
            'target_stage_id' => $stage->id,
            'rule_type'       => 'all',
            'rule_config'     => [],
        ]);
    }

    private function seedAndBuild(Tournament $tournament): void
    {
        app(SeedAndBuildService::class)->execute($tournament->fresh());
    }
}
