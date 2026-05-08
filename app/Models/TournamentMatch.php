<?php

namespace App\Models;

use App\Enums\BracketType;
use App\Enums\MatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Mapped to the `matches` table. Class name is `TournamentMatch` (not
 * `Match`) because `match` is a reserved keyword in PHP 8+ — the class
 * name `Match` is technically legal under namespaces but flaky across
 * IDEs and library reflection. Disambiguating at the class level keeps
 * the table name spec-aligned without paying the language-keyword cost.
 */
class TournamentMatch extends Model
{
    /** @use HasFactory<\Database\Factories\TournamentMatchFactory> */
    use HasFactory;

    protected $table = 'matches';

    protected $fillable = [
        'stage_id',
        'bracket_round',
        'bracket_position',
        'bracket_type',
        'group_number',
        'participant_a_type',
        'participant_a_id',
        'participant_b_type',
        'participant_b_id',
        'winner_participant_type',
        'winner_participant_id',
        'score_a',
        'score_b',
        'best_of',
        'winner_advances_to_match_id',
        'winner_advances_to_slot',
        'loser_advances_to_match_id',
        'loser_advances_to_slot',
        'status',
        'scheduled_at',
        'completed_at',
    ];

    protected $attributes = [
        'status'   => 'pending',
        'score_a'  => 0,
        'score_b'  => 0,
        'best_of'  => 1,
    ];

    protected function casts(): array
    {
        return [
            'status'       => MatchStatus::class,
            'bracket_type' => BracketType::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function participantA(): MorphTo
    {
        return $this->morphTo('participant_a');
    }

    public function participantB(): MorphTo
    {
        return $this->morphTo('participant_b');
    }

    /**
     * Polymorphic — Team or Player. Null until the match completes.
     */
    public function winner(): MorphTo
    {
        return $this->morphTo('winner_participant');
    }

    public function winnerAdvancesTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'winner_advances_to_match_id');
    }

    public function loserAdvancesTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'loser_advances_to_match_id');
    }

    public function games(): HasMany
    {
        return $this->hasMany(MatchGame::class, 'match_id')->orderBy('game_number');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class, 'match_id')->orderBy('created_at');
    }
}
