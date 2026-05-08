<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TournamentHost extends Model
{
    /** @use HasFactory<\Database\Factories\TournamentHostFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'display_name',
        'bio',
        'status',
        'approved_by_user_id',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Default status for newly-created rows. Overrideable by passing
     * status to fill / create().
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class, 'host_id');
    }
}
