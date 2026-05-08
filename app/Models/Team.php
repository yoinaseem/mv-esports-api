<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    /** @use HasFactory<\Database\Factories\TeamFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'game_id',
        'name',
        'tag',
        'logo_url',
        'created_by_player_id',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'created_by_player_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    /**
     * Tournament registrations this team has submitted (team-type
     * tournaments only).
     */
    public function tournamentRegistrations(): MorphMany
    {
        return $this->morphMany(TournamentRegistration::class, 'participant');
    }

    public function stageParticipations(): MorphMany
    {
        return $this->morphMany(StageParticipant::class, 'participant');
    }

    /**
     * Whether the given user owns the player who originally created this
     * team. The creator's authority persists across roster changes — they
     * stay in control even if no longer captain or rostered.
     */
    public function isCreatedBy(User $user): bool
    {
        return $user->players()->whereKey($this->created_by_player_id)->exists();
    }

    /**
     * Whether the given user holds an active captain seat on this team
     * (via any of their players).
     */
    public function isCaptainedBy(User $user): bool
    {
        $playerIds = $user->players()->pluck('id');

        return $this->members()
            ->whereIn('player_id', $playerIds)
            ->where('role', 'captain')
            ->whereNull('left_at')
            ->exists();
    }
}
