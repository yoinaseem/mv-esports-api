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
        /** @var TournamentMatch|null $match */
        $match        = $this->route('match');
        $type         = $this->input('winner_participant_type');
        $drawsAllowed = (bool) ($match?->stage?->config['allow_draws'] ?? false);

        // When the parent stage has draws enabled, both winner fields may be
        // null (representing a drawn game). `required_with` on each field
        // ensures the host can't submit one without the other — either both
        // identify a winner or neither does.
        return [
            'game_number'             => ['required', 'integer', 'min:1', 'unique:match_games,game_number,NULL,id,match_id,'.($match?->id ?? 0)],
            'winner_participant_type' => [
                $drawsAllowed ? 'nullable' : 'required',
                'string', 'in:team,player',
                'required_with:winner_participant_id',
            ],
            'winner_participant_id'   => [
                $drawsAllowed ? 'nullable' : 'required',
                'integer',
                'required_with:winner_participant_type',
                ...($match && $type ? [new WinnerIsParticipantAOrB($match, $type)] : []),
            ],
            'score_a'                 => ['nullable', 'integer', 'min:0'],
            'score_b'                 => ['nullable', 'integer', 'min:0'],
            'map_or_mode'             => ['nullable', 'string', 'max:255'],
        ];
    }
}
