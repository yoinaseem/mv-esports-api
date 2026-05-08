<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'email',
        'password',
        'date_of_birth',
        'country',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth'     => 'date',
            'password'          => 'hashed',
        ];
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * Tournament-host capability row. Modelled as hasOne since the
     * tournament_hosts table has unique(user_id) — a user can be a host or
     * not, but not "two host applications".
     */
    public function tournamentHost(): HasOne
    {
        return $this->hasOne(TournamentHost::class);
    }

    /**
     * Tournaments this user originally created (regardless of host_id —
     * managers create tournaments without a host_id).
     */
    public function createdTournaments(): HasMany
    {
        return $this->hasMany(Tournament::class, 'created_by_user_id');
    }
}
