<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Concert extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticketmaster_id', 'name', 'starts_at', 'venue_name', 'city', 'country',
        'lat', 'lng', 'ticket_url', 'status', 'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
