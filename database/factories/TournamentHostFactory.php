<?php

namespace Database\Factories;

use App\Models\TournamentHost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TournamentHost>
 */
class TournamentHostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'             => User::factory(),
            'organization_id'     => null,
            'display_name'        => fake()->name(),
            'bio'                 => fake()->optional()->sentence(),
            'status'              => 'pending',
            'approved_by_user_id' => null,
            'approved_at'         => null,
        ];
    }

    public function approved(?User $approver = null): static
    {
        return $this->state(function () use ($approver) {
            $approver ??= User::factory()->create();

            return [
                'status'              => 'approved',
                'approved_by_user_id' => $approver->id,
                'approved_at'         => now(),
            ];
        });
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
