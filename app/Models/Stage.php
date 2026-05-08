<?php

namespace App\Models;

use App\Enums\StageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
    /** @use HasFactory<\Database\Factories\StageFactory> */
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'name',
        'format',
        'sort_order',
        'start_date',
        'end_date',
        'status',
        'config',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status'     => StageStatus::class,
            'start_date' => 'date',
            'end_date'   => 'date',
            'config'     => 'array',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(StageParticipant::class);
    }

    /**
     * Qualification rules where THIS stage is the source — i.e. who
     * advances from this stage into others.
     */
    public function outgoingQualifications(): HasMany
    {
        return $this->hasMany(StageQualification::class, 'source_stage_id');
    }

    /**
     * Qualification rules where THIS stage is the target — i.e. who
     * feeds participants into this stage.
     */
    public function incomingQualifications(): HasMany
    {
        return $this->hasMany(StageQualification::class, 'target_stage_id');
    }

    /**
     * Alias for `incomingQualifications` used by Laravel's nested-route
     * scope binding. The URL `/stages/{stage}/qualifications/{qualification}`
     * with `Route::scopeBindings()` looks for a method matching the URL
     * segment ("qualifications") on the parent. The natural meaning is
     * "qualification rules feeding this stage" = incomingQualifications.
     */
    public function qualifications(): HasMany
    {
        return $this->incomingQualifications();
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class)->orderBy('bracket_round')->orderBy('bracket_position');
    }
}
