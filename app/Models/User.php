<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /** In-memory defaults mirroring the DB column defaults. */
    protected $attributes = [
        'nearby_mode' => 'country',
        'radius_miles' => 50,
        'notify_email' => true,
        'notify_push' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
            'password' => 'hashed',
            'home_lat' => 'float',
            'home_lng' => 'float',
            'radius_miles' => 'integer',
            'notify_email' => 'boolean',
            'notify_push' => 'boolean',
        ];
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'follows')
            ->using(Follow::class)
            ->withPivot(['alert_scope', 'last_seen_at'])
            ->withTimestamps();
    }
}
