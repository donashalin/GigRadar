<?php

use App\Models\User;

it('serves the web app manifest', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest['name'])->toBe('GigRadar')
        ->and($manifest['display'])->toBe('standalone');
    expect(file_exists(public_path('sw.js')))->toBeTrue();
    expect(file_exists(public_path('offline.html')))->toBeTrue();
});

it('links the manifest and apple touch icon from the app layout', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings')
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('rel="apple-touch-icon"', false);
});
