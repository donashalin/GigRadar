<?php

namespace App\Http\Controllers;

use App\Services\DiscoverFeed;
use App\Support\NearbyArea;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiscoverController extends Controller
{
    public function __invoke(Request $request, DiscoverFeed $feed): Response
    {
        $user = $request->user();

        return Inertia::render('Discover', [
            'groups' => $feed->for($user),
            'hasFollows' => $user->artists()->exists(),
            'hasArea' => NearbyArea::forUser($user)->isConfigured(),
        ]);
    }
}
