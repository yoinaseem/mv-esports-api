<?php

namespace Database\Factories;

use App\Enums\StageParticipantStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageParticipant>
 */
class StageParticipantFactory extends Factory
{
    public function definition(): array
    {
        // Default: a team participant on a fresh stage. Stage and team
        // are created together so their game_id/tournament line up.
        $stage = Stage::factory()->create();
        $team  = Team::factory()->create(['game_id' => $stage->tournament->game_id]);

        return [
            'stage_id'         => $stage->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => fake()->numberBetween(1, 16),
            'group_number'     => null,
            'status'           => StageParticipantStatus::Active,
            'final_position'   => null,
        ];
    }

    public function eliminated(int $position): static
    {
        return $this->state(fn () => [
            'status'         => StageParticipantStatus::Eliminated,
            'final_position' => $position,
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => ['status' => StageParticipantStatus::Withdrawn]);
    }

    public function inGroup(int $group): static
    {
        return $this->state(fn () => ['group_number' => $group]);
    }
}
