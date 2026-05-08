<?php

namespace App\Rules\MatchGame;

use App\Models\TournamentMatch;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The winner of a match_game must be one of the parent match's two
 * participants. Rejects "winner of game 3 is some random team that
 * isn't even in this match" — a typo or stale data attack.
 *
 * The Rule takes the parent match in the constructor and the request's
 * `(winner_participant_type, winner_participant_id)` are validated
 * against it. Used in CreateMatchGameRequest and UpdateMatchGameRequest.
 */
class WinnerIsParticipantAOrB implements ValidationRule
{
    public function __construct(
        private readonly TournamentMatch $match,
        private readonly string $winnerType,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $matchesA = $this->winnerType === $this->match->participant_a_type
            && (int) $value === (int) $this->match->participant_a_id;

        $matchesB = $this->winnerType === $this->match->participant_b_type
            && (int) $value === (int) $this->match->participant_b_id;

        if (! $matchesA && ! $matchesB) {
            $fail('The game winner must be one of the match participants.');
        }
    }
}
