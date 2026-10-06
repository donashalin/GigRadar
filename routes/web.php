<?php

use App\Http\Controllers\ArtistController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\DismissedArtistController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\MyArtistsController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// The route stays named "dashboard" because the auth controllers redirect there after login.
Route::get('dashboard', MyArtistsController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('search', SearchController::class)->name('search');
    Route::get('artists/{ticketmasterId}', [ArtistController::class, 'show'])
        ->whereAlphaNumeric('ticketmasterId')
        ->name('artists.show');
    Route::post('artists/{ticketmasterId}/follow', [FollowController::class, 'store'])
        ->whereAlphaNumeric('ticketmasterId')->name('follows.store');
    Route::patch('artists/{ticketmasterId}/follow', [FollowController::class, 'update'])
        ->whereAlphaNumeric('ticketmasterId')->name('follows.update');
    Route::delete('artists/{ticketmasterId}/follow', [FollowController::class, 'destroy'])
        ->whereAlphaNumeric('ticketmasterId')->name('follows.destroy');

    Route::get('discover', DiscoverController::class)->name('discover');
    Route::post('dismissed-artists', [DismissedArtistController::class, 'store'])
        ->middleware('throttle:dismissals')->name('dismissed-artists.store');
    Route::delete('dismissed-artists/{attractionId}', [DismissedArtistController::class, 'destroy'])
        ->whereAlphaNumeric('attractionId')->middleware('throttle:dismissals')->name('dismissed-artists.destroy');

    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:push-subscriptions')->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->middleware('throttle:push-subscriptions')->name('push-subscriptions.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
