<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TournamentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'name'                   => $this->name,
            'slug'                   => $this->slug,
            'game_id'                => $this->game_id,
            'host_id'                => $this->host_id,
            'organization_id'        => $this->organization_id,
            'created_by_user_id'     => $this->created_by_user_id,
            'approved_by_user_id'    => $this->approved_by_user_id,
            'approved_at'            => $this->approved_at,
            'participant_type'       => $this->participant_type,
            'registration_type'      => $this->registration_type,
            'status'                 => $this->status?->value,
            'description'            => $this->description,
            'start_date'             => $this->start_date?->toDateString(),
            'end_date'               => $this->end_date?->toDateString(),
            'registration_opens_at'  => $this->registration_opens_at,
            'registration_closes_at' => $this->registration_closes_at,
            'started_at'             => $this->started_at,
            'completed_at'           => $this->completed_at,
            'stream_url'             => $this->stream_url,
            'banner_url'             => $this->banner_url,
            'max_participants'       => $this->max_participants,
            'created_at'             => $this->created_at,
            'updated_at'             => $this->updated_at,

            // Nested data, included only when the relation has been eager-
            // loaded by the controller. Tournament index / show paths load
            // game + host.user + organization.
            'game' => $this->whenLoaded('game', fn () => [
                'id'       => $this->game->id,
                'name'     => $this->game->name,
                'slug'     => $this->game->slug,
                'icon_url' => $this->game->icon_url,
            ]),
            'host' => $this->whenLoaded('host', fn () => $this->host ? [
                'id'           => $this->host->id,
                'display_name' => $this->host->display_name,
                'user'         => ($this->host->relationLoaded('user') && $this->host->user) ? [
                    'id'           => $this->host->user->id,
                    'display_name' => $this->host->user->display_name,
                ] : null,
            ] : null),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? [
                'id'       => $this->organization->id,
                'name'     => $this->organization->name,
                'slug'     => $this->organization->slug,
                'logo_url' => $this->organization->logo_url,
            ] : null),
        ];
    }
}
