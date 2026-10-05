<?php

namespace App\Support;

use App\Services\Ticketmaster\ConcertData;

final class ConcertDiffer
{
    /**
     * @param  list<string>  $storedIds
     * @param  list<ConcertData>  $fetched
     * @return array{new: list<ConcertData>, existing: list<ConcertData>}
     */
    public static function diff(array $storedIds, array $fetched): array
    {
        $stored = array_flip($storedIds);
        $result = ['new' => [], 'existing' => []];
        $seen = [];

        foreach ($fetched as $concert) {
            if (isset($seen[$concert->id])) {
                continue;
            }
            $seen[$concert->id] = true;

            $result[isset($stored[$concert->id]) ? 'existing' : 'new'][] = $concert;
        }

        return $result;
    }
}
