<?php

namespace Database\Factories;

use App\Enums\MatchGameStatus;
use App\Models\MatchGame;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchGame>
 */
class MatchGameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'match_id'                => TournamentMatch::factory(),
            'game_number'             => 1,
            'winner_participant_type' => null,
            'winner_participant_id'   => null,
            'score_a'                 => null,
            'score_b'                 => null,
            'map_or_mode'             => null,
            'status'                  => MatchGameStatus::Pending,
            'completed_at'            => null,
        ];
    }

    /**
     * Mark the game completed with $winner (a Team or Player) winning,
     * scores set explicitly.
     */
    public function wonBy($winner, int $winnerScore = 13, int $loserScore = 7): static
    {
        return $this->state(function () use ($winner, $winnerScore, $loserScore) {
            $type = $winner instanceof \App\Models\Team ? 'team' : 'player';

            return [
                'winner_participant_type' => $type,
                'winner_participant_id'   => $winner->id,
                'score_a'                 => $winnerScore,
                'score_b'                 => $loserScore,
                'status'                  => MatchGameStatus::Completed,
                'completed_at'            => now(),
            ];
        });
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => MatchGameStatus::InProgress]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status'       => MatchGameStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
