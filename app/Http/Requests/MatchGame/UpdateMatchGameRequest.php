<?php

namespace App\Http\Requests\MatchGame;

use App\Models\MatchGame;
use App\Rules\MatchGame\WinnerIsParticipantAOrB;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMatchGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var MatchGame|null $game */
        $game  = $this->route('game');
        $match = $game?->match;
        $type  = $this->input('winner_participant_type', $game?->winner_participant_type);

        return [
            'winner_participant_type' => ['sometimes', 'string', 'in:team,player'],
            'winner_participant_id'   => [
                'sometimes',
                'integer',
                ...($match && $type ? [new WinnerIsParticipantAOrB($match, $type)] : []),
            ],
            'score_a'                 => ['sometimes', 'nullable', 'integer', 'min:0'],
            'score_b'                 => ['sometimes', 'nullable', 'integer', 'min:0'],
            'map_or_mode'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'                  => ['sometimes', 'string', 'in:pending,in_progress,completed'],
        ];
    }
}
