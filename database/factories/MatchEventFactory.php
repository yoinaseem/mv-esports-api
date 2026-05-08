<?php

namespace Database\Factories;

use App\Enums\MatchEventType;
use App\Models\MatchEvent;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchEvent>
 */
class MatchEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'match_id'           => TournamentMatch::factory(),
            'event_type'         => MatchEventType::ScoreUpdate,
            'payload'            => [],
            'created_by_user_id' => User::factory(),
            'created_at'         => now(),
        ];
    }

    public function statusChange(string $from = 'pending', string $to = 'scheduled'): static
    {
        return $this->state(fn () => [
            'event_type' => MatchEventType::StatusChange,
            'payload'    => ['from' => $from, 'to' => $to],
        ]);
    }

    public function walkover(): static
    {
        return $this->state(fn () => ['event_type' => MatchEventType::WalkoverCalled]);
    }

    public function gameCompleted(): static
    {
        return $this->state(fn () => ['event_type' => MatchEventType::GameCompleted]);
    }
}
