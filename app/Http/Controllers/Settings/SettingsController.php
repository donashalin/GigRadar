<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\CountryName;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $nearbySummary = $user->nearby_mode === 'radius'
            ? "Within {$user->radius_miles} miles"
            : 'Anywhere in '.(CountryName::for($user->home_country_code) ?? 'my country');

        return Inertia::render('settings/Index', [
            'alerts' => [
                'homeLocationName' => $user->home_location_name,
                'nearbySummary' => $nearbySummary,
                'notifyEmail' => (bool) $user->notify_email,
            ],
        ]);
    }
}
