<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'tournament_id' => $this->tournament_id,
            'name'          => $this->name,
            'format'        => $this->format,
            'sort_order'    => $this->sort_order,
            'start_date'    => $this->start_date?->toDateString(),
            'end_date'      => $this->end_date?->toDateString(),
            'status'        => $this->status?->value,
            'config'        => $this->config,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
