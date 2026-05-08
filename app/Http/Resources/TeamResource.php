<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'organization_id'      => $this->organization_id,
            'game_id'              => $this->game_id,
            'name'                 => $this->name,
            'tag'                  => $this->tag,
            'logo_url'             => $this->logo_url,
            'created_by_player_id' => $this->created_by_player_id,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        ];
    }
}
