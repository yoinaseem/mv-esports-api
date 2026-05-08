<?php

namespace App\Rules\Tournament;

use App\Enums\RegistrationStatus;
use App\Models\Tournament;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The same participant (team or player) can't have two simultaneous
 * non-terminal registrations for one tournament. Withdrawn / rejected
 * rows preserve history but shouldn't block re-registration.
 */
class ParticipantNotAlreadyActiveRegistration implements ValidationRule
{
    public function __construct(
        private readonly string $participantType,
        private readonly Tournament $tournament,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $alreadyActive = $this->tournament->registrations()
            ->where('participant_type', $this->participantType)
            ->where('participant_id', $value)
            ->whereNotIn('status', [
                RegistrationStatus::Rejected->value,
                RegistrationStatus::Withdrawn->value,
            ])
            ->exists();

        if ($alreadyActive) {
            $fail('This participant already has an active registration for this tournament.');
        }
    }
}
