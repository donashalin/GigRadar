<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Services\Ticketmaster\TicketmasterClient;
use Illuminate\Console\Command;
use Throwable;

class BackfillArtistClassifications extends Command
{
    protected $signature = 'gigradar:backfill-artist-classifications';

    protected $description = 'Fill in Ticketmaster genre and sub-genre for artists stored before classifications existed';

    public function handle(TicketmasterClient $ticketmaster): int
    {
        $artists = Artist::query()->whereNull('classifications_checked_at')->get();
        $updated = 0;

        foreach ($artists as $artist) {
            try {
                $data = $ticketmaster->attraction($artist->ticketmaster_id);
            } catch (Throwable $e) {
                report($e);
                $this->warn("Could not look up artist {$artist->id}: {$e->getMessage()}");

                continue;
            }

            $artist->forceFill([
                'genre_id' => $data->genreId,
                'genre_name' => $data->genreName,
                'sub_genre_id' => $data->subGenreId,
                'sub_genre_name' => $data->subGenreName,
                'classifications_checked_at' => now(),
            ])->save();

            if ($data->genreId !== null) {
                $updated++;
            }
        }

        $this->info("Updated {$updated} of {$artists->count()} artists.");

        return self::SUCCESS;
    }
}
