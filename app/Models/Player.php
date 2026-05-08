<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    /** @use HasFactory<\Database\Factories\PlayerFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'game_id',
        'gamertag',
        'rank_or_tier',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Team-membership rows (active and historical). Filter on
     * `whereNull('left_at')` for the current-roster view.
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    /**
     * Teams this player originally created. Independent of current roster
     * — creator authority persists even after leaving.
     */
    public function createdTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'created_by_player_id');
    }
}
