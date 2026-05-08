<?php

namespace App\Models;

use App\Enums\MatchEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log + live-feed source. No `updated_at` — events
 * are immutable. Created via the MatchEventLogger service from
 * controllers and the advancement service (commit 9).
 */
class MatchEvent extends Model
{
    /** @use HasFactory<\Database\Factories\MatchEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null; // events have only created_at

    protected $fillable = [
        'match_id',
        'event_type',
        'payload',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => MatchEventType::class,
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
