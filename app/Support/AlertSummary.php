<?php

namespace App\Support;

use App\Models\Concert;
use Illuminate\Support\Collection;

final class AlertSummary
{
    /** @param Collection<int, Concert> $concerts */
    public static function for(Collection $concerts): string
    {
        $first = $concerts->sortBy(fn (Concert $c) => ($c->local_date ?? $c->starts_at)?->format('Y-m-d H:i'))->first();
        $date = ($first->local_date ?? $first->starts_at)->format('j M');
        $place = $first->city !== '' && $first->city !== null ? $first->city : $first->venue_name;
        $count = $concerts->count();

        return $count === 1
            ? "New date: {$place} – {$date}"
            : "{$count} new dates, including {$place} – {$date}";
    }
}
