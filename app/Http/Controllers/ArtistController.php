<?php

namespace App\Http\Controllers;

use App\Models\Concert;
use App\Services\ArtistResolver;
use App\Services\ArtistSync;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArtistController extends Controller
{
    public function show(Request $request, string $ticketmasterId, ArtistResolver $resolver, ArtistSync $sync): Response
    {
        try {
            $artist = $resolver->resolve($ticketmasterId);
        } catch (TicketmasterException $e) {
            abort($e->isNotFound() ? 404 : 503);
        }

        $refreshFailed = false;
        if ($artist->isStale()) {
            try {
                $sync->syncEvents($artist);
            } catch (TicketmasterException $e) {
                report($e);
                $refreshFailed = true;
            }
        }

        $user = $request->user();
        $follow = $artist->followers()->where('users.id', $user->id)->first()?->pivot;
        if ($follow) {
            $user->artists()->updateExistingPivot($artist->id, ['last_seen_at' => now()]);
        }

        $concerts = $artist->concerts()->upcoming()->get();

        return Inertia::render('artists/Show', [
            'artist' => [
                'ticketmasterId' => $artist->ticketmaster_id,
                'name' => $artist->name,
                'imageUrl' => $artist->image_url,
            ],
            'concerts' => $concerts->map(fn (Concert $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'startsAt' => $c->starts_at->toIso8601String(),
                'localDate' => $c->local_date?->toDateString(),
                'venueName' => $c->venue_name,
                'city' => $c->city,
                'country' => $c->country,
                'ticketUrl' => $c->ticket_url,
                'status' => $c->status,
            ])->values(),
            'following' => $follow !== null,
            'alertScope' => $follow?->alert_scope,
            'refreshFailed' => $refreshFailed,
        ]);
    }
}
