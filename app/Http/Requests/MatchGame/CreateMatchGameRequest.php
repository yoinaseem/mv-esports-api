<?php

namespace App\Http\Requests\MatchGame;

use App\Models\TournamentMatch;
use App\Rules\MatchGame\WinnerIsParticipantAOrB;
use Illuminate\Foundation\Http\FormRequest;

class CreateMatchGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // MatchGamePolicy handles caller authz.
    }

    public function rules(): array
    {
        /** @var TournamentMatch $match */
        $match = $this->route('match');
        $type  = $this->input('winner_participant_type');

        return [
            'game_number'             => ['required', 'integer', 'min:1', 'unique:match_games,game_number,NULL,id,match_id,'.($match?->id ?? 0)],
            'winner_participant_type' => ['required', 'string', 'in:team,player'],
            'winner_participant_id'   => [
                'required',
                'integer',
                ...($match && $type ? [new WinnerIsParticipantAOrB($match, $type)] : []),
            ],
            'score_a'                 => ['nullable', 'integer', 'min:0'],
            'score_b'                 => ['nullable', 'integer', 'min:0'],
            'map_or_mode'             => ['nullable', 'string', 'max:255'],
        ];
    }
}
