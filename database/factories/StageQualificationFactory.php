<?php

namespace Database\Factories;

use App\Models\Stage;
use App\Models\StageQualification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageQualification>
 */
class StageQualificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_stage_id' => Stage::factory(),
            'target_stage_id' => Stage::factory(),
            'rule_type'       => 'top_n',
            'rule_config'     => ['n' => 4],
        ];
    }

    /**
     * Source-from-tournament-registrations rule (entry-point for the
     * earliest stage).
     */
    public function fromRegistrations(): static
    {
        return $this->state(fn () => ['source_stage_id' => null]);
    }

    public function topNPerGroup(int $perGroup = 2): static
    {
        return $this->state(fn () => [
            'rule_type'   => 'top_n_per_group',
            'rule_config' => ['per_group' => $perGroup, 'placement_strategy' => 'cross_group'],
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn () => [
            'rule_type'   => 'manual',
            'rule_config' => [],
        ]);
    }

    public function all(): static
    {
        return $this->state(fn () => [
            'rule_type'   => 'all',
            'rule_config' => [],
        ]);
    }
}
