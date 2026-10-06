<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocoderException;
use App\Services\Geocoding\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AlertSettingsController extends Controller
{
    public const RADIUS_OPTIONS = [25, 50, 100, 250];

    public function location(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Location', [
            'homeLocationName' => $user->home_location_name,
        ]);
    }

    public function nearMe(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/NearMe', [
            'nearbyMode' => $user->nearby_mode,
            'radiusMiles' => $user->radius_miles,
            'homeCountryCode' => $user->home_country_code,
            'hasHomeLocation' => $user->home_lat !== null && $user->home_lng !== null,
            'radiusOptions' => self::RADIUS_OPTIONS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'home_location_name' => ['nullable', 'string', 'max:255', 'required_with:home_lat,home_lng', 'present_with:home_lat,home_lng'],
            'home_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:home_location_name', 'present_with:home_location_name,home_lng'],
            'home_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:home_location_name', 'present_with:home_location_name,home_lat'],
            'home_country_code' => ['nullable', 'string', 'size:2', 'alpha:ascii'],
            'radius_miles' => ['sometimes', 'integer', Rule::in(self::RADIUS_OPTIONS)],
            'nearby_mode' => ['sometimes', Rule::in(['country', 'radius'])],
            'notify_email' => ['sometimes', 'boolean'],
            'notify_similar' => ['sometimes', 'boolean'],
        ]);

        // A country code only makes sense alongside the location it describes.
        if (! array_key_exists('home_lat', $validated)) {
            unset($validated['home_country_code']);
        }

        // The country code belongs to the location: never keep a stale one when the location changes or is cleared.
        if (array_key_exists('home_lat', $validated)) {
            $code = $validated['home_lat'] === null ? null : ($validated['home_country_code'] ?? null);
            $validated['home_country_code'] = ($code === null || $code === '') ? null : strtoupper($code);
        }

        // Omitted location keys are absent from $validated, so the existing location is kept.
        $request->user()->forceFill($validated)->save();

        return $request->input('redirect_to') === 'settings' ? to_route('settings') : back();
    }

    public function places(Request $request, Geocoder $geocoder): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json([]);
        }

        try {
            return response()->json(array_map(fn (Place $p) => $p->toArray(), $geocoder->search($q)));
        } catch (GeocoderException $e) {
            report($e);

            return response()->json(['message' => 'Location search is unavailable right now.'], 503);
        }
    }

    public function reverse(Request $request, Geocoder $geocoder): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        try {
            $place = $geocoder->reverse((float) $validated['lat'], (float) $validated['lng']);
        } catch (GeocoderException $e) {
            report($e);

            return response()->json(['message' => 'Location lookup is unavailable right now.'], 503);
        }

        return $place
            ? response()->json($place->toArray())
            : response()->json(['message' => "Couldn't work out where that is."], 404);
    }
}
