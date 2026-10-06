<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DismissedArtistController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/HiddenArtists', [
            'artists' => $request->user()->dismissedArtists()->orderBy('attraction_name')
                ->get(['attraction_ticketmaster_id', 'attraction_name'])
                ->map(fn ($d) => ['attractionId' => $d->attraction_ticketmaster_id, 'name' => $d->attraction_name])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'attraction_ticketmaster_id' => ['required', 'alpha_num', 'max:64'],
            'attraction_name' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->dismissedArtists()->firstOrCreate(
            ['attraction_ticketmaster_id' => $validated['attraction_ticketmaster_id']],
            ['attraction_name' => $validated['attraction_name']],
        );

        return back();
    }

    public function destroy(Request $request, string $attractionId): RedirectResponse
    {
        $request->user()->dismissedArtists()->where('attraction_ticketmaster_id', $attractionId)->delete();

        return back();
    }
}
