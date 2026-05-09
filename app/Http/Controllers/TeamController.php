<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeamResource;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeamController extends Controller
{
    /**
     * List teams
     *
     * Public list. Filterable by `?game_id`, `?organization_id`, `?created_by_player_id`. Soft-deleted teams excluded by default.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $teams = Team::query()
            ->when($request->filled('game_id'), fn ($q) => $q->where('game_id', $request->integer('game_id')))
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')))
            ->when($request->filled('created_by_player_id'), fn ($q) => $q->where('created_by_player_id', $request->integer('created_by_player_id')))
            ->orderBy('name')
            ->paginate($this->perPage($request, 20));

        return TeamResource::collection($teams);
    }

    /**
     * Show a team
     *
     * Public read for a single team by id.
     */
    public function show(Team $team): TeamResource
    {
        $team->load(['members.player.user']);

        return new TeamResource($team);
    }

    /**
     * Create a team
     *
     * Authenticated; the caller must own the player nominated as creator (`created_by_player_id`). The creator player's `game_id` must match the team's `game_id` — a Valorant team can't be created by a Rocket League player profile. Team names are unique within a game.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id'      => ['nullable', 'integer', 'exists:organizations,id'],
            'game_id'              => ['required', 'integer', 'exists:games,id'],
            'name'                 => ['required', 'string', 'max:255'],
            'tag'                  => ['nullable', 'string', 'max:8'],
            'logo_url'             => ['nullable', 'url', 'max:2048'],
            'created_by_player_id' => ['required', 'integer', 'exists:players,id'],
        ]);

        $creator = Player::where('id', $data['created_by_player_id'])
            ->where('user_id', $request->user()->id)
            ->first();
        abort_unless($creator, 403, 'You can only create teams under a player profile you own.');

        abort_unless(
            $creator->game_id === $data['game_id'],
            422,
            'The creator player must belong to the same game as the team.'
        );

        $request->validate([
            'name' => ['unique:teams,name,NULL,id,game_id,'.$data['game_id']],
        ]);

        $team = Team::create($data);

        return (new TeamResource($team))->response()->setStatusCode(201);
    }

    /**
     * Update a team
     *
     * Allowed for the team creator, an active captain, or a superadmin. Patch `name`, `tag`, `logo_url`, `organization_id`. Name uniqueness within game is re-checked excluding the row itself.
     */
    public function update(Request $request, Team $team): TeamResource
    {
        $this->authorizeTeamAdmin($request, $team);

        $data = $request->validate([
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'name'            => ['sometimes', 'string', 'max:255',
                'unique:teams,name,'.$team->id.',id,game_id,'.$team->game_id,
            ],
            'tag'             => ['nullable', 'string', 'max:8'],
            'logo_url'        => ['nullable', 'url', 'max:2048'],
        ]);

        $team->update($data);

        return new TeamResource($team);
    }

    /**
     * Archive a team
     *
     * Soft-delete. Creator OR superadmin only — captains can manage the roster but cannot dissolve the team. Tournament history (any past matches the team played) remains resolvable via `withTrashed()`.
     */
    public function destroy(Request $request, Team $team): JsonResponse
    {
        $user         = $request->user();
        $isCreator    = $team->isCreatedBy($user);
        $isSuperadmin = $user->hasRole('superadmin');

        abort_unless($isCreator || $isSuperadmin, 403, 'Only the team creator or a superadmin may delete the team.');

        $team->delete();

        return response()->json(['message' => 'Team archived.']);
    }

    private function authorizeTeamAdmin(Request $request, Team $team): void
    {
        $user         = $request->user();
        $isCreator    = $team->isCreatedBy($user);
        $isCaptain    = $team->isCaptainedBy($user);
        $isSuperadmin = $user->hasRole('superadmin');

        abort_unless(
            $isCreator || $isCaptain || $isSuperadmin,
            403,
            'Only the team creator, an active captain, or a superadmin may modify this team.'
        );
    }
}
