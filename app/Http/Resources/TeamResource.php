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

            // Members surfaced inline on team show. Each member nests
            // player + user for display. Eager-loaded by the show action.
            'members' => $this->whenLoaded('members', fn () =>
                $this->members->map(fn ($m) => [
                    'id'        => $m->id,
                    'player_id' => $m->player_id,
                    'role'      => $m->role,
                    'joined_at' => $m->joined_at,
                    'left_at'   => $m->left_at,
                    'player'    => ($m->relationLoaded('player') && $m->player) ? [
                        'id'       => $m->player->id,
                        'gamertag' => $m->player->gamertag,
                        'user'     => ($m->player->relationLoaded('user') && $m->player->user) ? [
                            'id'           => $m->player->user->id,
                            'display_name' => $m->player->user->display_name,
                        ] : null,
                    ] : null,
                ])->all(),
            ),
        ];
    }
}
