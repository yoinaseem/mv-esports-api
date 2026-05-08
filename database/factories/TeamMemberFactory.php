<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMember>
 */
class TeamMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id'   => Team::factory(),
            'player_id' => Player::factory(),
            'role'      => 'player',
            'joined_at' => now(),
            'left_at'   => null,
        ];
    }

    public function captain(): static
    {
        return $this->state(fn () => ['role' => 'captain']);
    }

    public function substitute(): static
    {
        return $this->state(fn () => ['role' => 'substitute']);
    }

    public function left(): static
    {
        return $this->state(fn () => ['left_at' => now()]);
    }
}
