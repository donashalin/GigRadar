<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Services\ArtistResolver;
use App\Services\ArtistSync;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FollowController extends Controller
{
    public function store(Request $request, string $ticketmasterId, ArtistResolver $resolver, ArtistSync $sync): RedirectResponse
    {
        try {
            $artist = $resolver->resolve($ticketmasterId);
        } catch (TicketmasterException $e) {
            return back()->with('error', $e->isNotFound() ? "We couldn't find that artist." : "Couldn't follow right now. Please try again.");
        }

        try {
            $request->user()->artists()->attach($artist, ['last_seen_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // Double-submit: already following, keep the existing row untouched.
        }

        // Following a hidden artist un-hides them.
        $request->user()->dismissedArtists()->where('attraction_ticketmaster_id', $artist->ticketmaster_id)->delete();

        // Store current dates now so they never trigger "new date" alerts later.
        if (! $artist->seeded) {
            try {
                $sync->syncEvents($artist);
            } catch (TicketmasterException $e) {
                report($e); // the scheduler seeds it on its next run instead
            }
        }

        return back();
    }

    public function update(Request $request, string $ticketmasterId): RedirectResponse
    {
        $validated = $request->validate([
            'alert_scope' => ['required', Rule::in(['everywhere', 'nearby'])],
        ]);

        $artist = Artist::where('ticketmaster_id', $ticketmasterId)->firstOrFail();
        $user = $request->user();
        abort_unless($user->artists()->where('artists.id', $artist->id)->exists(), 404);
        $user->artists()->updateExistingPivot($artist->id, $validated);

        return back();
    }

    public function destroy(Request $request, string $ticketmasterId): RedirectResponse
    {
        $artist = Artist::where('ticketmaster_id', $ticketmasterId)->firstOrFail();
        $request->user()->artists()->detach($artist->id);

        return back();
    }
}
