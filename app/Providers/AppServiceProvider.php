<?php

namespace App\Providers;

use App\Services\Geocoding\Geocoder;
use App\Services\Ticketmaster\TicketmasterClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('geo-search', fn (Request $r) => Limit::perMinute(30)->by('geo-search:'.$r->user()?->id));
        RateLimiter::for('push-subscriptions', fn (Request $r) => Limit::perMinute(20)->by('push-subscriptions:'.$r->user()?->id));
        RateLimiter::for('test-alert', fn (Request $r) => Limit::perMinute(3)->by('test-alert:'.$r->user()?->id));
        RateLimiter::for('dismissals', fn (Request $r) => Limit::perMinute(60)->by('dismissals:'.$r->user()?->id));
        RateLimiter::for('geo-reverse', fn (Request $r) => Limit::perMinute(10)->by('geo-reverse:'.$r->user()?->id));
    }
}
