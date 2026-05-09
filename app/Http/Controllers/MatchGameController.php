<?php

namespace App\Http\Controllers;

use App\Enums\MatchGameStatus;
use App\Enums\MatchStatus;
use App\Http\Requests\MatchGame\CreateMatchGameRequest;
use App\Http\Requests\MatchGame\UpdateMatchGameRequest;
use App\Http\Resources\MatchGameResource;
use App\Models\MatchGame;
use App\Models\TournamentMatch;
use App\Services\Match\MatchEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class MatchGameController extends Controller
{
    /**
     * List games for a match
     *
     * Public list scoped to a match. Sorted by `game_number`.
     */
    public function index(Request $request, TournamentMatch $match): AnonymousResourceCollection
    {
        return MatchGameResource::collection(
            $match->games()
                ->with(['winner' => fn ($q) => $q->morphWith([
                    \App\Models\Player::class => ['user'],
                ])])
                ->orderBy('game_number')
                ->paginate($this->perPage($request, 20))
        );
    }

    /**
     * Record a game result
     *
     * Tournament admin only. Creates a new match_game row with the winner, raw scores, and optional map/mode. Defaults the game's status to `Completed` since this endpoint is the host reporting a finished game. The MatchGameObserver fires automatically and recomputes `match.score_a` / `match.score_b`. Emits `score_update` and `game_completed` events for the live feed.
     */
    public function store(
        CreateMatchGameRequest $request,
        TournamentMatch $match,
        MatchEventLogger $logger,
    ): JsonResponse {
        $this->authorize('create', [MatchGame::class, $match]);

        $data = $request->validated();

        // Wrap in a transaction so the observer's auto-completion cascade
        // (advancement service, downstream populations) becomes a savepoint.
        // If the post-observer logging or anything else throws, the whole
        // game-record + cascade rolls back as one unit.
        $game = DB::transaction(function () use ($data, $match, $logger, $request) {
            $game = MatchGame::create([
                ...$data,
                'match_id'     => $match->id,
                'status'       => MatchGameStatus::Completed,
                'completed_at' => now(),
            ]);

            // Match scores have been recomputed by the observer; refresh the
            // match instance so the logger sees the new totals.
            $match->refresh();

            $logger->logGameCompleted($game, $request->user());
            $logger->logScoreUpdate($match, $request->user(), [
                'game_id'     => $game->id,
                'game_number' => $game->game_number,
                'score_a'     => $match->score_a,
                'score_b'     => $match->score_b,
            ]);

            return $game;
        });

        return (new MatchGameResource($game))->response()->setStatusCode(201);
    }

    /**
     * Update a game result
     *
     * Tournament admin only. Used to fix a previously-recorded game (typo in the score, wrong winner, etc.). The observer recomputes match scores automatically. Emits a `score_update` event.
     */
    public function update(
        UpdateMatchGameRequest $request,
        MatchGame $game,
        MatchEventLogger $logger,
    ): MatchGameResource {
        $this->authorize('update', $game);

        DB::transaction(function () use ($request, $game, $logger) {
            $game->update($request->validated());

            $match = $game->match->fresh();
            $logger->logScoreUpdate($match, $request->user(), [
                'game_id' => $game->id,
                'score_a' => $match->score_a,
                'score_b' => $match->score_b,
                'reason'  => 'game_updated',
            ]);
        });

        return new MatchGameResource($game);
    }

    /**
     * Delete a game result
     *
     * Tournament admin only. Hard-removes the row; observer recomputes match scores. For preserving record of a mistake, prefer PATCH to update fields rather than delete.
     */
    public function destroy(
        Request $request,
        MatchGame $game,
        MatchEventLogger $logger,
    ): JsonResponse {
        $this->authorize('delete', $game);

        DB::transaction(function () use ($game, $logger, $request) {
            $match = $game->match;
            $game->delete();

            $match = $match->fresh();
            $logger->logScoreUpdate($match, $request->user(), [
                'score_a' => $match->score_a,
                'score_b' => $match->score_b,
                'reason'  => 'game_deleted',
            ]);
        });

        return response()->json(['message' => 'Game deleted.']);
    }
}
