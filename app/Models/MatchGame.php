<?php

namespace App\Models;

use App\Enums\MatchGameStatus;
use App\Observers\MatchGameObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Per-game scoring within a match. The MatchGameObserver fires on saved
 * and deleted to recompute the parent match's score_a / score_b — those
 * are stored explicitly on the match for query simplicity but derived
 * from this table.
 */
#[ObservedBy([MatchGameObserver::class])]
class MatchGame extends Model
{
    /** @use HasFactory<\Database\Factories\MatchGameFactory> */
    use HasFactory;

    protected $fillable = [
        'match_id',
        'game_number',
        'winner_participant_type',
        'winner_participant_id',
        'score_a',
        'score_b',
        'map_or_mode',
        'status',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status'       => MatchGameStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }

    public function winner(): MorphTo
    {
        return $this->morphTo('winner_participant');
    }
}
