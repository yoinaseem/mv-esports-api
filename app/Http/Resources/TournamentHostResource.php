<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TournamentHostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'user_id'             => $this->user_id,
            'organization_id'     => $this->organization_id,
            'display_name'        => $this->display_name,
            'bio'                 => $this->bio,
            'status'              => $this->status,
            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_at'         => $this->approved_at,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
