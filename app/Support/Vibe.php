<?php

namespace App\Support;

use App\Models\Artist;
use Illuminate\Support\Collection;

/** Turns a user's followed artists into weighted Ticketmaster classification buckets. */
final class Vibe
{
    private const CATCH_ALL = ['undefined', 'other'];

    /**
     * @param  Collection<int, Artist>  $artists
     * @return list<array{id: string, name: string, weight: int, artists: list<string>}>
     */
    public static function for(Collection $artists): array
    {
        $buckets = [];

        foreach ($artists as $artist) {
            $class = self::usable($artist->sub_genre_id, $artist->sub_genre_name)
                ?? self::usable($artist->genre_id, $artist->genre_name);

            if ($class === null) {
                continue;
            }

            [$id, $name] = $class;
            $buckets[$id] ??= ['id' => $id, 'name' => $name, 'weight' => 0, 'artists' => []];
            $buckets[$id]['weight']++;
            $buckets[$id]['artists'][] = $artist->name;
        }

        foreach ($buckets as &$bucket) {
            sort($bucket['artists'], SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($bucket);

        $list = array_values($buckets);
        usort($list, fn ($a, $b) => [$b['weight'], mb_strtolower($a['name']), $a['id']] <=> [$a['weight'], mb_strtolower($b['name']), $b['id']]);

        return $list;
    }

    /** @return array{string, string}|null */
    private static function usable(?string $id, ?string $name): ?array
    {
        if ($id === null || $id === '' || $name === null || trim($name) === '') {
            return null;
        }

        return in_array(mb_strtolower(trim($name)), self::CATCH_ALL, true) ? null : [$id, $name];
    }
}
