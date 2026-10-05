<?php

use App\Models\User;

it('redirects unverified users away from the dashboard', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
});

it('lets verified users see the dashboard', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
});
