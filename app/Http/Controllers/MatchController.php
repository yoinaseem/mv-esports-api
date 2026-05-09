<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Http\Requests\Match\UpdateMatchRequest;
use App\Http\Resources\TournamentMatchResource;
use App\Models\Stage;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\Advancement\MatchAdvancementService;
use App\Services\Match\MatchEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class MatchController extends Controller
{
    /**
     * List matches in a stage
     *
     * Public list scoped to a stage. Sorted by `bracket_round` then `bracket_position` (the natural left-to-right reading order of a bracket). Optional `?bracket_type=winners|losers|grand_final|group` filter, `?status` filter.
     */
    public function index(
        Request $request,
        Tournament $tournament,
        Stage $stage,
    ): AnonymousResourceCollection {
        abort_unless($stage->tournament_id === $tournament->id, 404);

        $matches = $stage->matches()
            ->when($request->filled('bracket_type'), fn ($q) => $q->where('bracket_type', $request->string('bracket_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->get();

        return TournamentMatchResource::collection($matches);
    }

    /**
     * Show a match
     *
     * Public read for a single match by id.
     */
    public function show(TournamentMatch $match): TournamentMatchResource
    {
        return new TournamentMatchResource($match);
    }

    /**
     * Update a match
     *
     * Tournament admin only. Patch sparse fields — `scheduled_at` and `best_of`. Status changes happen through verb endpoints (`/walkover`) or as side effects of services in later commits (advancement, bracket generation). `best_of` is locked once any game has been recorded or the match enters a terminal state, since changing the threshold retroactively would alter how recorded games resolve.
     */
    public function update(UpdateMatchRequest $request, TournamentMatch $match): TournamentMatchResource
    {
        $this->authorize('update', $match);

        $data = $request->validated();

        if (array_key_exists('best_of', $data)) {
            abort_if(
                $match->status->isTerminal(),
                422,
                'Cannot change best_of on a match in a terminal state.',
            );
            abort_if(
                $match->games()->exists(),
                422,
                'Cannot change best_of after games have been recorded.',
            );
        }

        $match->update($data);

        return new TournamentMatchResource($match);
    }

    /**
     * Call a walkover
     *
     * Tournament admin only. Marks the match as `walkover` (forfeit) and sets the winner from the request payload. Emits a `walkover_called` event. Allowed from `Scheduled` or `InProgress` status.
     */
    public function walkover(
        Request $request,
        TournamentMatch $match,
        MatchEventLogger $logger,
        MatchAdvancementService $advancement,
    ): TournamentMatchResource {
        $this->authorize('walkover', $match);

        $data = $request->validate([
            'winner_participant_type' => ['required', 'string', 'in:team,player'],
            'winner_participant_id'   => ['required', 'integer'],
            'reason'                  => ['nullable', 'string', 'max:1000'],
        ]);

        $matchesA = $data['winner_participant_type'] === $match->participant_a_type
            && (int) $data['winner_participant_id'] === (int) $match->participant_a_id;
        $matchesB = $data['winner_participant_type'] === $match->participant_b_type
            && (int) $data['winner_participant_id'] === (int) $match->participant_b_id;

        abort_unless($matchesA || $matchesB, 422,
            'The walkover winner must be one of the match participants.');

        $previousStatus = $match->status;

        abort_unless(
            $previousStatus->canTransitionTo(MatchStatus::Walkover),
            422,
            sprintf('Cannot transition match from %s to walkover.', $previousStatus->value),
        );

        // Wrap in a transaction so the status update + events + advancement
        // cascade are all-or-nothing. Advancement opens its own transaction
        // which becomes a savepoint here.
        DB::transaction(function () use ($match, $data, $previousStatus, $logger, $advancement, $request) {
            $match->update([
                'status'                  => MatchStatus::Walkover,
                'winner_participant_type' => $data['winner_participant_type'],
                'winner_participant_id'   => $data['winner_participant_id'],
                'completed_at'            => now(),
            ]);

            $logger->logWalkoverCalled($match, $request->user(), [
                'winner_participant_type' => $data['winner_participant_type'],
                'winner_participant_id'   => $data['winner_participant_id'],
                'reason'                  => $data['reason'] ?? null,
            ]);
            $logger->logStatusChange($match, $request->user(), $previousStatus, MatchStatus::Walkover);

            // Cascade: propagate winner / loser, check stage / tournament completion.
            $advancement->advance($match);
        });

        return new TournamentMatchResource($match->fresh());
    }
}
