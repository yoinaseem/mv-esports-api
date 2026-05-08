<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchGameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'match_id'                => $this->match_id,
            'game_number'             => $this->game_number,
            'winner_participant_type' => $this->winner_participant_type,
            'winner_participant_id'   => $this->winner_participant_id,
            'score_a'                 => $this->score_a,
            'score_b'                 => $this->score_b,
            'map_or_mode'             => $this->map_or_mode,
            'status'                  => $this->status?->value,
            'completed_at'            => $this->completed_at,
            'created_at'              => $this->created_at,
            'updated_at'              => $this->updated_at,
        ];
    }
}
