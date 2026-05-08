<?php

namespace App\Http\Requests\TournamentRegistration;

use App\Enums\RegistrationStatus;
use App\Models\Tournament;
use App\Rules\Tournament\ParticipantBelongsToGame;
use App\Rules\Tournament\ParticipantNotAlreadyActiveRegistration;
use App\Rules\Tournament\ParticipantTypeMatchesTournament;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CreateRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Tournament status / registration_type checks live in the controller.
    }

    public function rules(): array
    {
        /** @var Tournament $tournament */
        $tournament = $this->route('tournament');
        $type       = $this->input('participant_type');

        return [
            'participant_type' => [
                'required',
                'string',
                'in:team,player',
                new ParticipantTypeMatchesTournament($tournament),
            ],
            'participant_id' => [
                'required',
                'integer',
                // Game-match rule does its own existence check, but we add a
                // type guard so it doesn't run with a junk type.
                ...($type === 'team' || $type === 'player' ? [
                    new ParticipantBelongsToGame($type, $tournament),
                    new ParticipantNotAlreadyActiveRegistration($type, $tournament),
                ] : []),
            ],
        ];
    }

    /**
     * Cross-field invariant that doesn't tie to a single request key —
     * "this user has no other active registration here." Runs after the
     * field-level rules pass.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Tournament $tournament */
                $tournament = $this->route('tournament');

                $alreadyRegistered = $tournament->registrations()
                    ->where('registered_by_user_id', $this->user()->id)
                    ->whereNotIn('status', [
                        RegistrationStatus::Rejected->value,
                        RegistrationStatus::Withdrawn->value,
                    ])
                    ->exists();

                if ($alreadyRegistered) {
                    $validator->errors()->add(
                        'participant_id',
                        'You already have an active registration for this tournament.'
                    );
                }
            },
        ];
    }
}
