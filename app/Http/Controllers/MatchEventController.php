<?php

namespace App\Http\Controllers;

use App\Http\Resources\MatchEventResource;
use App\Models\TournamentMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MatchEventController extends Controller
{
    /**
     * List events for a match
     *
     * Read-only audit / live feed for a match. Sorted by `created_at` ascending. Drives polling-based viewer updates per DESIGN.md §2 — clients poll this endpoint on the order of every 5–10 seconds while a match is in progress. Optional `?since=<timestamp>` returns only events newer than the given time so the client can fetch incrementally; malformed timestamps are rejected with 422 rather than propagating a Carbon parse error.
     */
    public function index(Request $request, TournamentMatch $match): AnonymousResourceCollection
    {
        $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $events = $match->events()
            ->when($request->filled('since'), fn ($q) => $q->where('created_at', '>', $request->date('since')))
            ->get();

        return MatchEventResource::collection($events);
    }
}
