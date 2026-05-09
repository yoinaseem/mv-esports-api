<?php

namespace App\Support;

use App\Models\Player;
use App\Models\Team;

/**
 * Single-source-of-truth serialiser for a participant (Team or Player)
 * surfacing in API responses. Used by:
 *   - TournamentMatchResource (participantA, participantB, winner)
 *   - StageParticipantResource (participant)
 *   - TournamentRegistrationResource (participant)
 *   - MatchGameResource (winner)
 *
 * Output is type-discriminated — clients branch on the `type` field
 * to decide whether they're rendering a team card or a player card.
 *
 * Player's nested `user` block is included only when the relation is
 * already loaded — callers should `morphWith([Player::class => ['user']])`
 * in their eager-load if they want it. Otherwise it falls back to null.
 */
class ParticipantPayload
{
    public static function serialize(mixed $participant): ?array
    {
        if ($participant === null) {
            return null;
        }

        if ($participant instanceof Team) {
            return [
                'type' => 'team',
                'id'   => $participant->id,
                'name' => $participant->name,
                'tag'  => $participant->tag,
            ];
        }

        if ($participant instanceof Player) {
            return [
                'type'     => 'player',
                'id'       => $participant->id,
                'gamertag' => $participant->gamertag,
                'user'     => ($participant->relationLoaded('user') && $participant->user)
                    ? ['id' => $participant->user->id, 'display_name' => $participant->user->display_name]
                    : null,
            ];
        }

        return null;
    }
}
