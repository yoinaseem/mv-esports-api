<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'logo_url'        => $this->logo_url,
            'description'     => $this->description,
            'owner_user_id'   => $this->owner_user_id,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,

            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id'           => $this->owner->id,
                'display_name' => $this->owner->display_name,
            ] : null),
        ];
    }
}
