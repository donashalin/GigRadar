<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Concert;
use App\Services\Ticketmaster\ConcertData;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Support\ConcertDiffer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ArtistSync
{
    public function __construct(private readonly TicketmasterClient $ticketmaster) {}

    /**
     * Fetch the artist's upcoming concerts and store them.
     * Sets seeded = true and last_checked_at = now on success.
     *
     * @return Collection<int, Concert> concerts first stored by this call. Concerts found while
     *                                  seeding are already stamped alerted_at, so callers must drive
     *                                  alerts from `alerted_at IS NULL` on seeded artists, NOT from
     *                                  this return value.
     *
     * @throws \App\Services\Ticketmaster\TicketmasterException
     */
    public function syncEvents(Artist $artist): Collection
    {
        $wasSeeded = (bool) $artist->newQuery()->whereKey($artist->getKey())->value('seeded');
        $fetched = $this->ticketmaster->upcomingEvents($artist->ticketmaster_id);
        $storedIds = $artist->concerts()->pluck('ticketmaster_id')->all();
        ['new' => $new, 'existing' => $existing] = ConcertDiffer::diff($storedIds, $fetched);

        return DB::transaction(function () use ($artist, $new, $existing, $wasSeeded) {
            $now = now();

            $created = collect($new)
                ->map(fn (ConcertData $c) => $artist->concerts()->createOrFirst(
                    ['ticketmaster_id' => $c->id],
                    [...$this->attributes($c), 'first_seen_at' => $now, 'alerted_at' => $wasSeeded ? null : $now],
                ))
                ->filter(fn (Concert $c) => $c->wasRecentlyCreated)
                ->values();

            foreach ($existing as $c) {
                $artist->concerts()->where('ticketmaster_id', $c->id)->update($this->attributes($c));
            }

            $artist->forceFill(['seeded' => true, 'last_checked_at' => $now])->save();

            return $created;
        });
    }

    private function attributes(ConcertData $c): array
    {
        return [
            'name' => $c->name,
            'starts_at' => $c->startsAt,
            'venue_name' => $c->venueName,
            'city' => $c->city,
            'country' => $c->country,
            'lat' => $c->lat,
            'lng' => $c->lng,
            'ticket_url' => $c->ticketUrl,
            'status' => $c->status,
        ];
    }
}
