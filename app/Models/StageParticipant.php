<?php

namespace App\Models;

use App\Enums\StageParticipantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StageParticipant extends Model
{
    /** @use HasFactory<\Database\Factories\StageParticipantFactory> */
    use HasFactory;

    protected $fillable = [
        'stage_id',
        'participant_type',
        'participant_id',
        'seed',
        'group_number',
        'status',
        'final_position',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => StageParticipantStatus::class,
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * Polymorphic — Team or Player via the morphMap registered in
     * AppServiceProvider.
     */
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }
}
