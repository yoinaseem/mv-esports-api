<?php

namespace App\Http\Controllers;

use App\Http\Resources\PlayerResource;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlayerController extends Controller
{
    /**
     * List players
     *
     * Public list of player profiles. Supports `?game_id` and `?user_id` filters for the common "players in this game" / "this user's profiles" lookups.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $players = Player::query()
            ->with(['user', 'game'])
            ->when($request->filled('game_id'), fn ($q) => $q->where('game_id', $request->integer('game_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->orderBy('gamertag')
            ->paginate($this->perPage($request, 20));

        return PlayerResource::collection($players);
    }

    /**
     * Show a player
     *
     * Public read for a single player profile by id.
     */
    public function show(Player $player): PlayerResource
    {
        $player->load(['user', 'game']);
        return new PlayerResource($player);
    }

    /**
     * Create a player profile
     *
     * Authenticated users create their own player profile, one per game. The `user_id` is forced to the caller — no spoofing other users' profiles. Two friendly 422 errors: "You already have a player profile for this game" and "That gamertag is taken in this game." Host-created orphan rows (user_id=null) are out of scope for the MVP UI.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'game_id'      => ['required', 'integer', 'exists:games,id'],
            'gamertag'     => ['required', 'string', 'max:255'],
            'rank_or_tier' => ['nullable', 'string', 'max:255'],
        ]);

        $data['user_id'] = $request->user()->id;

        $request->validate([
            'game_id' => ['unique:players,game_id,NULL,id,user_id,'.$data['user_id']],
        ], [
            'game_id.unique' => 'You already have a player profile for this game.',
        ]);
        $request->validate([
            'gamertag' => ['unique:players,gamertag,NULL,id,game_id,'.$data['game_id']],
        ]);

        $player = Player::create($data);

        return (new PlayerResource($player))->response()->setStatusCode(201);
    }

    /**
     * Update a player profile
     *
     * Owner-only — players are personal identity records, no admin override. Patch `gamertag` (uniqueness re-checked within the player's game) or `rank_or_tier`.
     */
    public function update(Request $request, Player $player): PlayerResource
    {
        abort_unless(
            $player->user_id === $request->user()->id,
            403,
            'You can only update your own player profile.'
        );

        $data = $request->validate([
            'gamertag'     => ['sometimes', 'string', 'max:255',
                'unique:players,gamertag,'.$player->id.',id,game_id,'.$player->game_id,
            ],
            'rank_or_tier' => ['nullable', 'string', 'max:255'],
        ]);

        $player->update($data);

        return new PlayerResource($player);
    }

    /**
     * Delete a player profile
     *
     * Owner-only. Hard-delete; will fail with a foreign-key violation if the player is on any team's roster (TeamMember.player_id is RESTRICT) or registered for any tournament.
     */
    public function destroy(Request $request, Player $player): JsonResponse
    {
        abort_unless(
            $player->user_id === $request->user()->id,
            403,
            'You can only delete your own player profile.'
        );

        $player->delete();

        return response()->json(['message' => 'Player profile deleted.']);
    }
}
