<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Valorant', 'Rocket League', 'Counter-Strike 2', 'League of Legends',
            'Dota 2', 'Overwatch 2', 'FIFA 24', 'Apex Legends',
        ]).' '.fake()->numerify('##');

        return [
            'name'      => $name,
            'slug'      => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'icon_url'  => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
