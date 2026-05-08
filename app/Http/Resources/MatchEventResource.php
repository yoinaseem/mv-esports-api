<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'match_id'           => $this->match_id,
            'event_type'         => $this->event_type?->value,
            'payload'            => $this->payload,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at'         => $this->created_at,
        ];
    }
}
