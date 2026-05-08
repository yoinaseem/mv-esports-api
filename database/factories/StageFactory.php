<?php

namespace Database\Factories;

use App\Enums\StageStatus;
use App\Models\Stage;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stage>
 */
class StageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tournament_id' => Tournament::factory(),
            'name'          => fake()->randomElement(['Group Stage', 'Playoffs', 'Main Event', 'Qualifiers']),
            'format'        => 'single_elim',
            'sort_order'    => 0,
            'start_date'    => null,
            'end_date'      => null,
            'status'        => StageStatus::Pending,
            'config'        => null,
        ];
    }

    public function doubleElim(): static
    {
        return $this->state(fn () => [
            'format' => 'double_elim',
            'config' => ['grand_final_reset' => true],
        ]);
    }

    public function roundRobin(int $groups = 1, int $groupSize = 4): static
    {
        return $this->state(fn () => [
            'format' => 'round_robin',
            'config' => ['groups' => $groups, 'group_size' => $groupSize],
        ]);
    }

    public function swiss(int $rounds = 5): static
    {
        return $this->state(fn () => [
            'format' => 'swiss',
            'config' => ['rounds' => $rounds],
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => StageStatus::InProgress]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => StageStatus::Completed]);
    }
}
