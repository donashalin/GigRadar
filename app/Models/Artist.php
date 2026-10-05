<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artist extends Model
{
    use HasFactory;

    protected $fillable = ['ticketmaster_id', 'name', 'image_url', 'seeded', 'last_checked_at'];

    protected function casts(): array
    {
        return [
            'seeded' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function concerts(): HasMany
    {
        return $this->hasMany(Concert::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows')
            ->using(Follow::class)
            ->withPivot(['alert_scope', 'last_seen_at'])
            ->withTimestamps();
    }

    public function isStale(): bool
    {
        return $this->last_checked_at === null || $this->last_checked_at->lt(now()->subHours(6));
    }
}
