<?php

namespace App\Rules\Tournament;

use App\Models\Tournament;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Asserts that the submitted participant_type matches the tournament's
 * participant_type. A team can't register for a player tournament, etc.
 */
class ParticipantTypeMatchesTournament implements ValidationRule
{
    public function __construct(
        private readonly Tournament $tournament,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== $this->tournament->participant_type) {
            $fail(sprintf(
                'This tournament accepts only %s participants.',
                $this->tournament->participant_type,
            ));
        }
    }
}
