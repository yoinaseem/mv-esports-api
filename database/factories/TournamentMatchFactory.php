<?php

namespace Database\Factories;

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TournamentMatch>
 */
class TournamentMatchFactory extends Factory
{
    protected $model = TournamentMatch::class;

    public function definition(): array
    {
        // Default: a single-elim round-1 winners-bracket match between
        // two teams from a fresh stage.
        $stage = Stage::factory()->create();
        $teamA = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
        $teamB = Team::factory()->create(['game_id' => $stage->tournament->game_id]);

        return [
            'stage_id'           => $stage->id,
            'bracket_round'      => 1,
            'bracket_position'   => 0,
            'bracket_type'       => BracketType::Winners,
            'group_number'       => null,
            'participant_a_type' => 'team',
            'participant_a_id'   => $teamA->id,
            'participant_b_type' => 'team',
            'participant_b_id'   => $teamB->id,
            'best_of'            => 1,
            'status'             => MatchStatus::Scheduled,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'participant_a_type' => null,
            'participant_a_id'   => null,
            'status'             => MatchStatus::Pending,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => MatchStatus::InProgress]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status'       => MatchStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function walkover(): static
    {
        return $this->state(fn () => [
            'status'       => MatchStatus::Walkover,
            'completed_at' => now(),
        ]);
    }

    public function conditional(): static
    {
        return $this->state(fn () => [
            'status'       => MatchStatus::Conditional,
            'bracket_type' => BracketType::GrandFinal,
        ]);
    }

    public function bestOf(int $n): static
    {
        return $this->state(fn () => ['best_of' => $n]);
    }
}
