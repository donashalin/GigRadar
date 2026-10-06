<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('requires auth and a verified email', function () {
    $this->get('/settings')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->get('/settings')->assertRedirect(route('verification.notice'));
});

it('renders the settings list with summaries', function (array $attrs, ?string $home, string $summary, bool $email) {
    $user = User::factory()->create([...$attrs, 'notify_email' => $email]);

    $this->actingAs($user)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page->component('settings/Index')
            ->where('alerts.homeLocationName', $home)
            ->where('alerts.nearbySummary', $summary)
            ->where('alerts.notifyEmail', $email));
})->with([
    'country mode with GB' => [['nearby_mode' => 'country', 'home_country_code' => 'GB', 'home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1], 'Leicester', 'Anywhere in United Kingdom', true],
    'country mode without code' => [['nearby_mode' => 'country', 'home_country_code' => null, 'home_location_name' => null, 'home_lat' => null, 'home_lng' => null], null, 'Anywhere in my country', false],
    'radius mode' => [['nearby_mode' => 'radius', 'radius_miles' => 50], null, 'Within 50 miles', true],
]);

it('exposes the similar artists flag', function (bool $on) {
    $user = User::factory()->create(['notify_similar' => $on]);

    $this->actingAs($user)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page->where('alerts.notifySimilar', $on));
})->with([true, false]);

it('defaults similar artists to off', function () {
    expect(User::factory()->create()->fresh()->notify_similar)->toBeFalse();
});
