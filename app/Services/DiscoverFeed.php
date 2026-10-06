<?php

namespace App\Services;

use App\Models\DiscoveryEvent;
use App\Models\User;
use App\Support\NearbyArea;
use App\Support\Vibe;
use Carbon\CarbonInterface;

/** Builds a user's Discover feed: nearby gigs by artists in the same classifications as the ones they follow. */
class DiscoverFeed
{
    private const GROUP_LIMIT = 10;

    /**
     * @return list<array{id: string, name: string, artists: list<string>, items: list<array<string, mixed>>}>
     */
    public function for(User $user, ?CarbonInterface $firstSeenSince = null): array
    {
        $area = NearbyArea::forUser($user);
        $followed = $user->artists()->get();
        $vibe = Vibe::for($followed);

        if ($vibe === [] || ! $area->isConfigured()) {
            return [];
        }

        $excluded = $followed->pluck('ticketmaster_id')
            ->merge($user->dismissedArtists()->pluck('attraction_ticketmaster_id'))
            ->unique()->values()->all();

        $events = DiscoveryEvent::query()
            ->upcoming()
            ->whereIn('classification_id', array_column($vibe, 'id'))
            ->where('status', '!=', 'cancelled')
            ->when($area->homeCountryCode !== null, fn ($q) => $q->where('country', $area->homeCountryCode))
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('attraction_ticketmaster_id', $excluded))
            ->when($firstSeenSince, fn ($q) => $q->where('first_seen_at', '>=', $firstSeenSince))
            ->get()
            ->filter(fn (DiscoveryEvent $e) => $area->contains($e->country, $e->lat, $e->lng) !== false)
            ->groupBy('classification_id');

        $placed = [];
        $groups = [];

        foreach ($vibe as $bucket) {
            $items = [];

            // Events are already soonest first, so the first hit per attraction is its soonest gig.
            foreach ($events->get($bucket['id'], []) as $event) {
                if (count($items) >= self::GROUP_LIMIT) {
                    break;
                }
                $attractionId = $event->attraction_ticketmaster_id;
                if (isset($placed[$attractionId])) {
                    continue;
                }
                $placed[$attractionId] = true;
                $items[] = $this->item($event, $area);
            }

            if ($items !== []) {
                $groups[] = [
                    'id' => $bucket['id'],
                    'name' => $bucket['name'],
                    'artists' => $bucket['artists'],
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    private function item(DiscoveryEvent $event, NearbyArea $area): array
    {
        $distance = $area->distanceMiles($event->lat, $event->lng);

        return [
            'eventId' => $event->id,
            'attractionId' => $event->attraction_ticketmaster_id,
            'attractionName' => $event->attraction_name,
            'imageUrl' => $event->attraction_image_url,
            'localDate' => $event->local_date?->toDateString(),
            'startsAt' => $event->starts_at->toIso8601String(),
            'venueName' => $event->venue_name,
            'city' => $event->city,
            'distanceMiles' => $distance !== null ? (int) round($distance) : null,
            'ticketUrl' => $event->ticket_url,
        ];
    }
}
