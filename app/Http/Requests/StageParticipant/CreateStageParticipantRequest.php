<?php

namespace App\Http\Requests\StageParticipant;

use App\Models\Stage;
use App\Rules\Tournament\ParticipantBelongsToGame;
use App\Rules\Tournament\ParticipantTypeMatchesTournament;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CreateStageParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // StageParticipantPolicy handles caller authz.
    }

    public function rules(): array
    {
        /** @var Stage $stage */
        $stage      = $this->route('stage');
        $tournament = $stage?->tournament;
        $type       = $this->input('participant_type');

        return [
            'participant_type' => [
                'required',
                'string',
                'in:team,player',
                ...($tournament ? [new ParticipantTypeMatchesTournament($tournament)] : []),
            ],
            'participant_id' => [
                'required',
                'integer',
                ...($type === 'team' || $type === 'player'
                    ? [new ParticipantBelongsToGame($type, $tournament)]
                    : []),
            ],
            'seed'         => ['required', 'integer', 'min:1'],
            'group_number' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Reject duplicate participant rows in the same stage. Bracket
     * generation must be able to assume distinct participants per stage.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Stage $stage */
                $stage = $this->route('stage');
                $type  = $this->input('participant_type');
                $id    = $this->input('participant_id');

                if (! $stage || ! $type || ! $id) {
                    return;
                }

                $exists = $stage->participants()
                    ->where('participant_type', $type)
                    ->where('participant_id', $id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add(
                        'participant_id',
                        'This participant is already in this stage.'
                    );
                }
            },
        ];
    }
}
