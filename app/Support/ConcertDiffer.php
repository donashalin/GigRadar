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

        foreach ($fetched as $concert) {
            $result[isset($stored[$concert->id]) ? 'existing' : 'new'][] = $concert;
        }

        return $result;
    }
}
