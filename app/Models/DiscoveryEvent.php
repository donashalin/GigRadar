<?php

namespace App\Models;

use App\Models\Concerns\HasUpcomingScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscoveryEvent extends Model
{
    use HasFactory, HasUpcomingScope;

    protected $fillable = [
        'ticketmaster_event_id', 'classification_id', 'attraction_ticketmaster_id', 'attraction_name',
        'attraction_image_url', 'name', 'starts_at', 'local_date', 'venue_name', 'city', 'country',
        'lat', 'lng', 'ticket_url', 'status', 'first_seen_at', 'from_seed',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'local_date' => 'date:Y-m-d',
            'first_seen_at' => 'datetime',
            'from_seed' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }
}
