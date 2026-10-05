<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Follow extends Pivot
{
    protected $table = 'follows';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
