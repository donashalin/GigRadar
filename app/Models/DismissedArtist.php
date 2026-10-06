<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DismissedArtist extends Model
{
    protected $fillable = ['user_id', 'attraction_ticketmaster_id', 'attraction_name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
