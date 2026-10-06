<?php

namespace App\Providers;

use App\Services\Geocoding\Geocoder;
use App\Services\Ticketmaster\TicketmasterClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TicketmasterClient::class, fn () => new TicketmasterClient(
            (string) config('services.ticketmaster.key'),
            (int) config('services.ticketmaster.throttle_ms'),
        ));
        $this->app->singleton(Geocoder::class, fn () => new Geocoder((string) config('services.nominatim.user_agent')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
