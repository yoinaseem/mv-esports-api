<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'display_name'       => $this->display_name,
            'email'              => $this->email,
            'date_of_birth'      => $this->date_of_birth?->toDateString(),
            'country'            => $this->country,
            'roles'              => $this->getRoleNames(),
            // Effective permissions = role-derived ∪ direct grants.
            'permissions'        => $this->getAllPermissions()->pluck('name'),
            'direct_permissions' => $this->getDirectPermissions()->pluck('name'),
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
