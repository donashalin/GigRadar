<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Notifications\TestAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class TestAlertController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $notification = new TestAlert;

        if ($notification->via($user) === []) {
            return back()->with('error', 'Turn on email alerts or push on this device first.');
        }

        try {
            $user->notifyNow($notification);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', "Couldn't send the test alert. Try again later.");
        }

        return back()->with('success', 'Test alert sent.');
    }
}
