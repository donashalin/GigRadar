<?php

namespace App\Support;

use App\Models\Concert;
use App\Models\User;
use Illuminate\Support\Collection;

/** Decides which followers should be alerted about which pending concerts. */
final class RecipientSelector
{
    /**
     * @param  Collection<int, Concert>  $concerts  pending concerts, in the order they should be presented
     * @param  Collection<int, User>  $followers  loaded via Artist::followers() (pivot alert_scope)
     * @return array<int, Collection<int, Concert>> user id => concerts to alert; users with nothing are omitted
     */
    public static function select(Collection $concerts, Collection $followers): array
    {
        $alertable = $concerts->reject(fn (Concert $c) => $c->status === 'cancelled');
        $result = [];

        foreach ($followers as $user) {
            $mine = $user->pivot->alert_scope === 'nearby'
                ? self::nearby($user, $alertable)
                : $alertable;

            if ($mine->isNotEmpty()) {
                $result[$user->id] = $mine->values();
            }
        }

        return $result;
    }

    /** @param Collection<int, Concert> $concerts */
    private static function nearby(User $user, Collection $concerts): Collection
    {
        $area = NearbyArea::forUser($user);

        // null = can't judge (missing data) -> alert rather than silently drop (spec §7)
        return $concerts->filter(fn (Concert $c) => $area->contains($c->country ?: null, $c->lat, $c->lng) !== false);
    }
}
