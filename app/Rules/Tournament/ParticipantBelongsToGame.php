<?php

namespace App\Rules\Tournament;

use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Resolves the polymorphic participant by (type, id) and asserts its
 * game_id matches the tournament's. Game-match invariant — a Valorant
 * team can't register for a Rocket League tournament.
 *
 * Also doubles as an existence check: a missing participant fails here
 * with a "not found" message rather than the generic 422 a separate
 * `exists:` rule would emit.
 */
class ParticipantBelongsToGame implements ValidationRule
{
    public function __construct(
        private readonly string $participantType,
        private readonly Tournament $tournament,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $participant = match ($this->participantType) {
            'team'   => Team::find($value),
            'player' => Player::find($value),
            default  => null,
        };

        if ($participant === null) {
            $fail('The selected participant does not exist.');

            return;
        }

        if ($participant->game_id !== $this->tournament->game_id) {
            $fail('The participant must belong to the same game as the tournament.');
        }
    }
}
