<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'stage_id'         => $this->stage_id,
            'participant_type' => $this->participant_type, // morph alias
            'participant_id'   => $this->participant_id,
            'seed'             => $this->seed,
            'group_number'     => $this->group_number,
            'status'           => $this->status?->value,
            'final_position'   => $this->final_position,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
