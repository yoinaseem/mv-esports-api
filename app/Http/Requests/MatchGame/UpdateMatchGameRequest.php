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
        $game         = $this->route('game');
        $match        = $game?->match;
        $type         = $this->input('winner_participant_type', $game?->winner_participant_type);
        $drawsAllowed = (bool) ($match?->stage?->config['allow_draws'] ?? false);

        return [
            // Both winner fields nullable when stage allows draws; on PATCH the
            // host can flip a previously-decided game back to a draw by sending
            // {winner_participant_type: null, winner_participant_id: null}.
            'winner_participant_type' => $drawsAllowed
                ? ['sometimes', 'nullable', 'string', 'in:team,player']
                : ['sometimes', 'string', 'in:team,player'],
            'winner_participant_id'   => [
                'sometimes',
                $drawsAllowed ? 'nullable' : 'integer',
                ...($drawsAllowed ? ['integer'] : []),
                ...($match && $type ? [new WinnerIsParticipantAOrB($match, $type)] : []),
            ],
            'score_a'                 => ['sometimes', 'nullable', 'integer', 'min:0'],
            'score_b'                 => ['sometimes', 'nullable', 'integer', 'min:0'],
            'map_or_mode'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'                  => ['sometimes', 'string', 'in:pending,in_progress,completed'],
        ];
    }
}
