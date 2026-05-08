<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GameController extends Controller
{
    /**
     * List games
     *
     * Public list of games, sorted by name. Active games only by default; pass `?include_inactive=1` to include archived/inactive entries.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $games = Game::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return GameResource::collection($games);
    }

    /**
     * Show a game
     *
     * Public read for a single game by id.
     */
    public function show(Game $game): GameResource
    {
        return new GameResource($game);
    }

    /**
     * Create a game
     *
     * System-level catalog write. Requires the `games.manage` permission, granted to `superadmin` and `system_manager`. `is_active` defaults to true if omitted.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'slug'      => ['required', 'string', 'max:255', 'unique:games,slug'],
            'icon_url'  => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $game = Game::create($data);

        return (new GameResource($game))->response()->setStatusCode(201);
    }

    /**
     * Update a game
     *
     * Patch any of `name`, `slug`, `icon_url`, `is_active`. Slug uniqueness is enforced excluding the row itself. Requires the `games.manage` permission.
     */
    public function update(Request $request, Game $game): GameResource
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'slug'      => ['sometimes', 'string', 'max:255', 'unique:games,slug,'.$game->id],
            'icon_url'  => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $game->update($data);

        return new GameResource($game);
    }

    /**
     * Delete a game
     *
     * Hard-deletes the game. Will fail with a foreign key violation if any players, teams, or tournaments reference this game (the FKs are RESTRICT). Requires the `games.manage` permission.
     */
    public function destroy(Game $game): JsonResponse
    {
        $game->delete();

        return response()->json(['message' => 'Game deleted.']);
    }
}
