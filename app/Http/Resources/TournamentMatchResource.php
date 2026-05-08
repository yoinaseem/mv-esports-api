<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TournamentMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id,
            'stage_id'                    => $this->stage_id,
            'bracket_round'               => $this->bracket_round,
            'bracket_position'            => $this->bracket_position,
            'bracket_type'                => $this->bracket_type?->value,
            'group_number'                => $this->group_number,
            'participant_a_type'          => $this->participant_a_type,
            'participant_a_id'            => $this->participant_a_id,
            'participant_b_type'          => $this->participant_b_type,
            'participant_b_id'            => $this->participant_b_id,
            'winner_participant_type'     => $this->winner_participant_type,
            'winner_participant_id'       => $this->winner_participant_id,
            'score_a'                     => $this->score_a,
            'score_b'                     => $this->score_b,
            'best_of'                     => $this->best_of,
            'winner_advances_to_match_id' => $this->winner_advances_to_match_id,
            'winner_advances_to_slot'     => $this->winner_advances_to_slot,
            'loser_advances_to_match_id'  => $this->loser_advances_to_match_id,
            'loser_advances_to_slot'      => $this->loser_advances_to_slot,
            'status'                      => $this->status?->value,
            'scheduled_at'                => $this->scheduled_at,
            'completed_at'                => $this->completed_at,
            'created_at'                  => $this->created_at,
            'updated_at'                  => $this->updated_at,
        ];
    }
}
