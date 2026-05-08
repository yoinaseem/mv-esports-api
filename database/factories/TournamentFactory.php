<?php

namespace Database\Factories;

use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tournament>
 */
class TournamentFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+7 days', '+30 days');
        $end   = (clone $start)->modify('+2 days');

        $name = fake()->unique()->company().' Cup';

        return [
            'name'                   => $name,
            'slug'                   => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'game_id'                => Game::factory(),
            'host_id'                => null,
            'organization_id'        => null,
            'created_by_user_id'     => User::factory(),
            'approved_by_user_id'    => null,
            'approved_at'            => null,
            'participant_type'       => 'team',
            'registration_type'      => 'open',
            'status'                 => TournamentStatus::DraftPendingReview,
            'description'            => fake()->optional()->sentence(),
            'start_date'             => $start->format('Y-m-d'),
            'end_date'               => $end->format('Y-m-d'),
            'registration_opens_at'  => now()->subDay(),
            'registration_closes_at' => $start,
            'stream_url'             => null,
            'banner_url'             => null,
            'max_participants'       => 8,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => TournamentStatus::Draft]);
    }

    public function registrationOpen(): static
    {
        return $this->state(fn () => ['status' => TournamentStatus::RegistrationOpen]);
    }

    public function registrationClosed(): static
    {
        return $this->state(fn () => ['status' => TournamentStatus::RegistrationClosed]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => TournamentStatus::InProgress]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => TournamentStatus::Completed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => TournamentStatus::Cancelled]);
    }

    public function playerType(): static
    {
        return $this->state(fn () => ['participant_type' => 'player']);
    }
}
