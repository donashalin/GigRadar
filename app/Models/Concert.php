<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Concert extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticketmaster_id', 'name', 'starts_at', 'local_date', 'venue_name', 'city', 'country',
        'lat', 'lng', 'ticket_url', 'status', 'first_seen_at', 'alerted_at',
        'from_seed',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'local_date' => 'date:Y-m-d',
            'first_seen_at' => 'datetime',
            'alerted_at' => 'datetime',
            'from_seed' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /** Concerts on or after today at the venue (falls back to the UTC start when local_date is missing), soonest first. */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('local_date', '>=', today()->toDateString())
            ->orWhere(fn (Builder $q) => $q->whereNull('local_date')->where('starts_at', '>=', now()->startOfDay())))
            ->orderByRaw('COALESCE(local_date, DATE(starts_at))')
            ->orderBy('starts_at');
    }
}
