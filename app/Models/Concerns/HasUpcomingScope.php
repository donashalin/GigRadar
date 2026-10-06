<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasUpcomingScope
{
    /** Rows on or after today at the venue (falls back to the UTC start when local_date is missing), soonest first. */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('local_date', '>=', today()->toDateString())
            ->orWhere(fn (Builder $q) => $q->whereNull('local_date')->where('starts_at', '>=', now()->startOfDay())))
            ->orderByRaw('COALESCE(local_date, DATE(starts_at))')
            ->orderBy('starts_at');
    }
}
