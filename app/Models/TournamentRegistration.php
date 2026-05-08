<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TournamentRegistration extends Model
{
    /** @use HasFactory<\Database\Factories\TournamentRegistrationFactory> */
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'participant_type',
        'participant_id',
        'registered_by_user_id',
        'status',
        'seed',
        'registered_at',
    ];

    protected function casts(): array
    {
        return [
            'status'        => RegistrationStatus::class,
            'registered_at' => 'datetime',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * Polymorphic — resolves to a Team or Player via the morphMap registered
     * in AppServiceProvider.
     */
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The user who actually performed the registration. Distinct from the
     * participant — a team's captain registers their team; the captain is
     * the registrant, the team is the participant.
     */
    public function registrant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }
}
