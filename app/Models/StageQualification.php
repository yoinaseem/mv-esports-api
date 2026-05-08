<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageQualification extends Model
{
    /** @use HasFactory<\Database\Factories\StageQualificationFactory> */
    use HasFactory;

    protected $fillable = [
        'source_stage_id',
        'target_stage_id',
        'rule_type',
        'rule_config',
    ];

    protected function casts(): array
    {
        return [
            'rule_config' => 'array',
        ];
    }

    /**
     * Source stage. Null means the rule pulls participants from the
     * tournament's registrations (entry-point rule for the earliest stage).
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'source_stage_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'target_stage_id');
    }
}
