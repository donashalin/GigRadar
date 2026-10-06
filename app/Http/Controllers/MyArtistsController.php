<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\Concert;
use App\Support\NearbyArea;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class MyArtistsController extends Controller
{
    private const NEARBY_LIMIT = 10;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $artists = $user->artists()->orderBy('name')->get();
        $upcoming = Concert::query()->whereIn('artist_id', $artists->modelKeys())->upcoming()->get();
        $byArtist = $upcoming->groupBy('artist_id');
        $area = NearbyArea::forUser($user);

        return Inertia::render('MyArtists', [
            'artists' => $artists->map(fn (Artist $artist) => $this->artistRow($artist, $byArtist->get($artist->id, collect())))->values(),
            'hasHomeLocation' => $user->home_lat !== null && $user->home_lng !== null,
            'nearbyMode' => $user->nearby_mode,
            'homeCountryCode' => $user->home_country_code,
            'radiusMiles' => $user->radius_miles,
            'areaLabel' => $area->isConfigured() ? $this->areaLabel($area) : null,
            'nearby' => $area->isConfigured() ? $this->nearby($upcoming, $artists, $area) : [],
        ]);
    }

    /** @param Collection<int, Concert> $concerts this artist's upcoming concerts, soonest first */
    private function artistRow(Artist $artist, Collection $concerts): array
    {
        $lastSeen = $artist->pivot->last_seen_at;
        $next = $concerts->first(fn (Concert $c) => $c->status !== 'cancelled');

        return [
            'ticketmasterId' => $artist->ticketmaster_id,
            'name' => $artist->name,
            'imageUrl' => $artist->image_url,
            'alertScope' => $artist->pivot->alert_scope,
            'hasNew' => $concerts->contains(fn (Concert $c) => ! $c->from_seed && $c->status !== 'cancelled' && ($lastSeen === null || $c->first_seen_at->gt($lastSeen))),
            'nextConcert' => $next ? [
                'localDate' => $next->local_date?->toDateString(),
                'startsAt' => $next->starts_at->toIso8601String(),
                'city' => $next->city,
            ] : null,
        ];
    }

    /** "in United Kingdom" / "within 50 miles" */
    private function areaLabel(NearbyArea $area): string
    {
        if ($area->mode === 'radius') {
            return "within {$area->radiusMiles} miles";
        }

        $code = (string) $area->homeCountryCode;
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, 'en') : $code;
        if ($name === '' || $name === 'Unknown Region' || strcasecmp($name, $code) === 0) {
            $name = $code;
        }

        return 'in '.$name;
    }

    /**
     * @param  Collection<int, Concert>  $upcoming  soonest first
     * @param  Collection<int, Artist>  $artists
     */
    private function nearby(Collection $upcoming, Collection $artists, NearbyArea $area): array
    {
        $names = $artists->pluck('name', 'id');
        $ticketmasterIds = $artists->pluck('ticketmaster_id', 'id');

        return $upcoming
            ->filter(fn (Concert $c) => $c->status !== 'cancelled' && $area->contains($c->country, $c->lat, $c->lng) === true)
            ->take(self::NEARBY_LIMIT)
            ->map(fn (Concert $c) => [
                'id' => $c->id,
                'artistName' => $names[$c->artist_id],
                'artistTicketmasterId' => $ticketmasterIds[$c->artist_id],
                'localDate' => $c->local_date?->toDateString(),
                'startsAt' => $c->starts_at->toIso8601String(),
                'venueName' => $c->venue_name,
                'city' => $c->city,
                'distanceMiles' => ($distance = $area->distanceMiles($c->lat, $c->lng)) !== null ? (int) round($distance) : null,
            ])
            ->values()
            ->all();
    }
}
