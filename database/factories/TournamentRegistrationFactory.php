<?php

namespace Database\Factories;

use App\Enums\RegistrationStatus;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TournamentRegistration>
 */
class TournamentRegistrationFactory extends Factory
{
    public function definition(): array
    {
        // Default: a team registration for a fresh team-type tournament.
        $tournament = Tournament::factory()->registrationOpen()->create();
        $team       = Team::factory()->create(['game_id' => $tournament->game_id]);

        return [
            'tournament_id'         => $tournament->id,
            'participant_type'      => 'team',
            'participant_id'        => $team->id,
            'registered_by_user_id' => User::factory(),
            'status'                => RegistrationStatus::Pending,
            'seed'                  => null,
            'registered_at'         => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => RegistrationStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => RegistrationStatus::Rejected]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => ['status' => RegistrationStatus::Withdrawn]);
    }
}
