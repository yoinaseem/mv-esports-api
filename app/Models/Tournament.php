<?php

namespace App\Models;

use App\Enums\TournamentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tournament extends Model
{
    /** @use HasFactory<\Database\Factories\TournamentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'game_id',
        'host_id',
        'organization_id',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'participant_type',
        'registration_type',
        'status',
        'description',
        'start_date',
        'end_date',
        'registration_opens_at',
        'registration_closes_at',
        'started_at',
        'completed_at',
        'stream_url',
        'banner_url',
        'max_participants',
    ];

    protected function casts(): array
    {
        return [
            'status'                 => TournamentStatus::class,
            'start_date'             => 'date',
            'end_date'               => 'date',
            'registration_opens_at'  => 'datetime',
            'registration_closes_at' => 'datetime',
            'started_at'             => 'datetime',
            'completed_at'           => 'datetime',
            'approved_at'            => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(TournamentHost::class, 'host_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(TournamentRegistration::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('sort_order');
    }
}
