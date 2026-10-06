<?php

use App\Http\Controllers\Settings\AlertSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\TestAlertController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings', [SettingsController::class, 'index'])->name('settings');
    Route::get('settings/location', [AlertSettingsController::class, 'location'])->name('settings.location');
    Route::get('settings/near-me', [AlertSettingsController::class, 'nearMe'])->name('settings.near-me');
    Route::redirect('settings/alerts', '/settings', 301);
    Route::patch('settings/alerts', [AlertSettingsController::class, 'update'])->name('alerts.update');
    Route::post('settings/test-alert', [TestAlertController::class, 'store'])
        ->middleware('throttle:test-alert')->name('settings.test-alert');
    Route::get('settings/alerts/places', [AlertSettingsController::class, 'places'])
        ->middleware('throttle:geo-search')->name('alerts.places');
    Route::get('settings/alerts/reverse', [AlertSettingsController::class, 'reverse'])
        ->middleware('throttle:geo-reverse')->name('alerts.reverse');
});
