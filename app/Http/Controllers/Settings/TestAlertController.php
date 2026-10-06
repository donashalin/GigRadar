<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Notifications\TestAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TestAlertController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $notification = new TestAlert;

        if ($notification->via($user) === []) {
            return back()->with('error', 'Turn on email alerts or push on this device first.');
        }

        $user->notifyNow($notification);

        return back()->with('success', 'Test alert sent.');
    }
}
