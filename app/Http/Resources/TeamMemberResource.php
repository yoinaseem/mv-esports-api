<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'team_id'    => $this->team_id,
            'player_id'  => $this->player_id,
            'role'       => $this->role,
            'joined_at'  => $this->joined_at,
            'left_at'    => $this->left_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
