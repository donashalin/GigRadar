<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocoderException;
use Illuminate\Console\Command;

class BackfillCountryCodes extends Command
{
    protected $signature = 'gigradar:backfill-country-codes';

    protected $description = 'Fill in home_country_code for users who set a home location before country codes existed';

    public function handle(Geocoder $geocoder): int
    {
        $users = User::query()
            ->whereNotNull('home_lat')
            ->whereNotNull('home_lng')
            ->whereNull('home_country_code')
            ->get();
        $updated = 0;

        foreach ($users as $user) {
            try {
                $code = $geocoder->reverse($user->home_lat, $user->home_lng)?->countryCode;
            } catch (GeocoderException $e) {
                // Message never contains coordinates; keep the user's location out of output too.
                $this->warn("Could not look up user {$user->id}: {$e->getMessage()}");

                continue;
            }

            if ($code !== null) {
                $user->forceFill(['home_country_code' => $code])->save();
                $updated++;
            }
        }

        $this->info("Updated {$updated} of {$users->count()} users.");

        return self::SUCCESS;
    }
}
