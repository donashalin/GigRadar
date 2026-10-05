<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
