<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Ticketmaster\TicketmasterClient::class, fn () => new \App\Services\Ticketmaster\TicketmasterClient(
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
