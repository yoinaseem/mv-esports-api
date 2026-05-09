<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeamMemberResource;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeamMemberController extends Controller
{
    /**
     * List team members
     *
     * Public roster list (current + historical). Pass `?active=1` to filter to currently-active members (`left_at` is null). Sorted by `joined_at`.
     */
    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        $members = $team->members()
            ->when($request->boolean('active'), fn ($q) => $q->whereNull('left_at'))
            ->orderBy('joined_at')
            ->paginate($this->perPage($request, 20));

        return TeamMemberResource::collection($members);
    }

    /**
     * Add a roster member
     *
     * Add a player to the team's roster. Allowed for the team creator, an active captain, or a superadmin. The player's `game_id` must match the team's. Roles: `captain`, `player`, `substitute`. Re-adding a currently-active player returns 422; re-adding a player who left earlier creates a fresh roster row.
     */
    public function store(Request $request, Team $team): JsonResponse
    {
        $this->authorizeTeamAdmin($request, $team);

        $data = $request->validate([
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'role'      => ['required', 'string', 'in:captain,player,substitute'],
        ]);

        $player = Player::find($data['player_id']);
        abort_unless(
            $player->game_id === $team->game_id,
            422,
            'The player must belong to the same game as the team.'
        );

        $alreadyActive = $team->members()
            ->where('player_id', $data['player_id'])
            ->whereNull('left_at')
            ->exists();
        abort_if($alreadyActive, 422, 'That player is already on this team\'s active roster.');

        $member = TeamMember::create([
            'team_id'   => $team->id,
            'player_id' => $data['player_id'],
            'role'      => $data['role'],
            'joined_at' => now(),
        ]);

        return (new TeamMemberResource($member))->response()->setStatusCode(201);
    }

    /**
     * Update a roster member
     *
     * Two caller paths. A team admin (creator, active captain, or superadmin) may patch role and/or `left_at`. The player whose membership it is may patch `left_at` to leave the team — but cannot change their role; that's an admin-only action. Cross-team tampering returns 404.
     */
    public function update(Request $request, Team $team, TeamMember $member): TeamMemberResource
    {
        abort_unless($member->team_id === $team->id, 404);

        $user      = $request->user();
        $isAdmin   = $team->isCreatedBy($user) || $team->isCaptainedBy($user) || $user->hasRole('superadmin');
        $ownsPlayer = $user->players()->whereKey($member->player_id)->exists();

        abort_unless($isAdmin || $ownsPlayer, 403);

        $rules = [];
        if ($isAdmin) {
            $rules['role']    = ['sometimes', 'string', 'in:captain,player,substitute'];
            $rules['left_at'] = ['sometimes', 'nullable', 'date'];
        } else {
            $rules['left_at'] = ['required', 'date'];
            if ($request->has('role')) {
                abort(403, 'Only a team admin may change a member\'s role.');
            }
        }

        $data = $request->validate($rules);
        $member->update($data);

        return new TeamMemberResource($member);
    }

    /**
     * Hard-delete a roster row
     *
     * Creator or superadmin only. Captains and the player themselves should use PATCH with `left_at` to preserve history; this endpoint is for accidentally-added members where keeping the row would be misleading.
     */
    public function destroy(Request $request, Team $team, TeamMember $member): JsonResponse
    {
        abort_unless($member->team_id === $team->id, 404);

        $user         = $request->user();
        $isCreator    = $team->isCreatedBy($user);
        $isSuperadmin = $user->hasRole('superadmin');

        abort_unless($isCreator || $isSuperadmin, 403);

        $member->delete();

        return response()->json(['message' => 'Roster row removed.']);
    }

    private function authorizeTeamAdmin(Request $request, Team $team): void
    {
        $user = $request->user();
        $allowed = $team->isCreatedBy($user) || $team->isCaptainedBy($user) || $user->hasRole('superadmin');

        abort_unless($allowed, 403, 'Only the team creator, an active captain, or a superadmin may manage the roster.');
    }
}
