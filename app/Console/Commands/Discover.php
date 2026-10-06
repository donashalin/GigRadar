<?php

namespace App\Console\Commands;

use App\Models\DiscoveryEvent;
use App\Models\User;
use App\Services\Ticketmaster\DiscoveryEventData;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Support\Vibe;
use Illuminate\Console\Command;
use Throwable;

class Discover extends Command
{
    protected $signature = 'gigradar:discover';

    protected $description = 'Fetch upcoming gigs for every sub-genre users follow, into discovery_events';

    public function handle(TicketmasterClient $ticketmaster): int
    {
        $pairs = [];

        User::query()->whereNotNull('home_country_code')->with('artists')->each(function (User $user) use (&$pairs) {
            foreach (Vibe::for($user->artists) as $bucket) {
                $pairs[$bucket['id'].'|'.$user->home_country_code] = [$bucket['id'], $user->home_country_code];
            }
        });

        $fetched = 0;
        $created = 0;
        $failures = 0;

        foreach ($pairs as [$classificationId, $country]) {
            try {
                $events = $ticketmaster->eventsByClassification($classificationId, $country);
            } catch (Throwable $e) {
                report($e);
                $failures++;

                continue;
            }

            $fetched++;
            foreach ($events as $event) {
                try {
                    $created += $this->upsert($classificationId, $event) ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                    $failures++;
                }
            }
        }

        try {
            DiscoveryEvent::query()
                ->where(fn ($q) => $q->where('local_date', '<', today()->toDateString())
                    ->orWhere(fn ($q) => $q->whereNull('local_date')->where('starts_at', '<', now()->startOfDay())))
                ->delete();
        } catch (Throwable $e) {
            report($e);
            $failures++;
        }

        $this->info("Fetched {$fetched} classifications, stored {$created} new gigs, {$failures} failures.");

        return self::SUCCESS;
    }

    /** Returns true when a new row was inserted. */
    private function upsert(string $classificationId, DiscoveryEventData $data): bool
    {
        $c = $data->concert;
        $values = [
            'attraction_ticketmaster_id' => $data->attractionId,
            'attraction_name' => $data->attractionName,
            'attraction_image_url' => $data->attractionImageUrl,
            'name' => $c->name,
            'starts_at' => $c->startsAt,
            'local_date' => $c->localDate,
            'venue_name' => $c->venueName,
            'city' => $c->city,
            'country' => $c->country,
            'lat' => $c->lat,
            'lng' => $c->lng,
            'ticket_url' => $c->ticketUrl,
            'status' => $c->status,
        ];

        $row = DiscoveryEvent::firstOrNew(['classification_id' => $classificationId, 'ticketmaster_event_id' => $c->id]);
        $isNew = ! $row->exists;
        if ($isNew) {
            $row->first_seen_at = now();
        }
        $row->fill($values)->save();

        return $isNew;
    }
}
