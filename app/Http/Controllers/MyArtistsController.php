<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\Concert;
use App\Support\Geo;
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
        $hasHomeLocation = $user->home_lat !== null && $user->home_lng !== null;

        return Inertia::render('MyArtists', [
            'artists' => $artists->map(fn (Artist $artist) => $this->artistRow($artist, $byArtist->get($artist->id, collect())))->values(),
            'hasHomeLocation' => $hasHomeLocation,
            'radiusMiles' => $user->radius_miles,
            'nearby' => $hasHomeLocation ? $this->nearby($upcoming, $artists, $user->home_lat, $user->home_lng, $user->radius_miles) : [],
        ]);
    }

    /** @param Collection<int, Concert> $concerts this artist's upcoming concerts, soonest first */
    private function artistRow(Artist $artist, Collection $concerts): array
    {
        $lastSeen = $artist->pivot->last_seen_at;
        $next = $concerts->first();

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

    /**
     * @param  Collection<int, Concert>  $upcoming  soonest first
     * @param  Collection<int, Artist>  $artists
     */
    private function nearby(Collection $upcoming, Collection $artists, float $lat, float $lng, int $radiusMiles): array
    {
        $names = $artists->pluck('name', 'id');
        $ticketmasterIds = $artists->pluck('ticketmaster_id', 'id');

        return $upcoming
            ->filter(fn (Concert $c) => $c->status !== 'cancelled' && $c->lat !== null && $c->lng !== null)
            ->map(fn (Concert $c) => [$c, Geo::distanceMiles($lat, $lng, $c->lat, $c->lng)])
            ->filter(fn (array $pair) => $pair[1] <= $radiusMiles)
            ->take(self::NEARBY_LIMIT)
            ->map(fn (array $pair) => [
                'id' => $pair[0]->id,
                'artistName' => $names[$pair[0]->artist_id],
                'artistTicketmasterId' => $ticketmasterIds[$pair[0]->artist_id],
                'localDate' => $pair[0]->local_date?->toDateString(),
                'startsAt' => $pair[0]->starts_at->toIso8601String(),
                'venueName' => $pair[0]->venue_name,
                'city' => $pair[0]->city,
                'distanceMiles' => (int) round($pair[1]),
            ])
            ->values()
            ->all();
    }
}
