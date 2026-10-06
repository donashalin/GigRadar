<?php

use App\Http\Controllers\Settings\AlertSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/alerts');

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
    Route::get('settings/alerts', [AlertSettingsController::class, 'edit'])->name('alerts.edit');
    Route::patch('settings/alerts', [AlertSettingsController::class, 'update'])->name('alerts.update');
    Route::get('settings/alerts/places', [AlertSettingsController::class, 'places'])
        ->middleware('throttle:30,1')->name('alerts.places');
    Route::get('settings/alerts/reverse', [AlertSettingsController::class, 'reverse'])
        ->middleware('throttle:10,1')->name('alerts.reverse');
});
