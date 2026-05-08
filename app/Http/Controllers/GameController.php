<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GameController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $games = Game::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return GameResource::collection($games);
    }

    public function show(Game $game): GameResource
    {
        return new GameResource($game);
    }

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

    public function destroy(Game $game): JsonResponse
    {
        $game->delete();

        return response()->json(['message' => 'Game deleted.']);
    }
}
