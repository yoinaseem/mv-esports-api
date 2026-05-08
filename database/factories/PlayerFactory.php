<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Player;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'      => User::factory(),
            'game_id'      => Game::factory(),
            'gamertag'     => fake()->unique()->userName(),
            'rank_or_tier' => fake()->optional()->randomElement(['Iron', 'Bronze', 'Silver', 'Gold', 'Platinum', 'Diamond']),
        ];
    }

    /**
     * Host-created roster entry — no linked user account yet (DESIGN.md §4).
     */
    public function orphan(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
