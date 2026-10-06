<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Console\Command;

class BackfillArtistClassifications extends Command
{
    protected $signature = 'gigradar:backfill-artist-classifications';

    protected $description = 'Fill in Ticketmaster genre and sub-genre for artists stored before classifications existed';

    public function handle(TicketmasterClient $ticketmaster): int
    {
        $artists = Artist::query()->whereNull('genre_id')->get();
        $updated = 0;

        foreach ($artists as $artist) {
            try {
                $data = $ticketmaster->attraction($artist->ticketmaster_id);
            } catch (TicketmasterException $e) {
                $this->warn("Could not look up artist {$artist->id}: {$e->getMessage()}");

                continue;
            }

            if ($data->genreId === null) {
                continue;
            }

            $artist->forceFill([
                'genre_id' => $data->genreId,
                'genre_name' => $data->genreName,
                'sub_genre_id' => $data->subGenreId,
                'sub_genre_name' => $data->subGenreName,
            ])->save();
            $updated++;
        }

        $this->info("Updated {$updated} of {$artists->count()} artists.");

        return self::SUCCESS;
    }
}
