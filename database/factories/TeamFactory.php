<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        // Creator player and team must agree on game_id. Resolve the game
        // via the creator factory so the constraint holds in tests.
        $game = Game::factory()->create();

        return [
            'organization_id'      => null,
            'game_id'              => $game->id,
            'name'                 => fake()->unique()->company().' '.fake()->numerify('##'),
            'tag'                  => strtoupper(fake()->lexify('???')),
            'logo_url'             => null,
            'created_by_player_id' => Player::factory()->state(['game_id' => $game->id]),
        ];
    }

    /**
     * Affiliate the team with an organisation.
     */
    public function forOrganization($organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id ?? $organization]);
    }
}
