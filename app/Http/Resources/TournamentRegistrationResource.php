<?php

namespace App\Http\Resources;

use App\Support\ParticipantPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TournamentRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'tournament_id'         => $this->tournament_id,
            'participant_type'      => $this->participant_type, // 'team' | 'player' (morph alias)
            'participant_id'        => $this->participant_id,
            'registered_by_user_id' => $this->registered_by_user_id,
            'status'                => $this->status?->value,
            'seed'                  => $this->seed,
            'registered_at'         => $this->registered_at,
            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,

            'participant' => $this->whenLoaded('participant', fn () =>
                ParticipantPayload::serialize($this->participant),
            ),
        ];
    }
}
