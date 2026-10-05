<?php

namespace App\Http\Controllers;

use App\Services\Ticketmaster\ArtistData;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(Request $request, TicketmasterClient $ticketmaster): Response
    {
        $q = trim((string) $request->query('q', ''));
        $results = [];
        $error = null;

        if (mb_strlen($q) >= 2) {
            try {
                $results = Cache::remember(
                    'tm:search:'.md5(mb_strtolower($q)),
                    now()->addMinutes(10),
                    fn () => $ticketmaster->searchAttractions($q),
                );
            } catch (TicketmasterException $e) {
                report($e);
                $error = 'Search is unavailable right now. Please try again.';
            }
        }

        $followed = $request->user()->artists()->pluck('ticketmaster_id')->flip();

        return Inertia::render('Search', [
            'q' => $q,
            'results' => array_map(fn (ArtistData $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'imageUrl' => $a->imageUrl,
                'following' => $followed->has($a->id),
            ], $results),
            'error' => $error,
        ]);
    }
}
