<?php

namespace App\Http\Controllers;

use App\Notifications\NotificationPreferences;
use Illuminate\Http\Request;
use App\Notifications\PushTest;
use NotificationChannels\WebPush\PushSubscription;

/**
 * The browsers a user has subscribed to Web Push (backlog item 25). The browser asks for permission only
 * after the user presses a button; the subscription it then returns is saved here, one row per browser.
 * Subscriptions the push service rejects with 404 or 410 are removed by the channel's report handler.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|url:https|max:' . PushSubscription::ENDPOINT_MAX_LENGTH,
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'contentEncoding' => 'nullable|in:aesgcm,aes128gcm',
        ]);

        $user = $request->user();
        $user->updatePushSubscription($validated['endpoint'], $validated['keys']['p256dh'], $validated['keys']['auth'], $validated['contentEncoding'] ?? 'aes128gcm');
        NotificationPreferences::enableWebPushDefaults($user);

        return response()->json(['message' => 'اعلان مرورگر در این مرورگر فعال شد.']);
    }

    /** A test push to every browser the user has subscribed, from the settings page. */
    public function test(Request $request)
    {
        $user = $request->user();
        if (! $user->pushSubscriptions()->exists()) {
            return response()->json(['message' => 'هیچ مرورگری برای این حساب فعال نشده است.'], 422);
        }
        $user->notifyNow(new PushTest);

        return response()->json(['message' => 'اعلان آزمایشی فرستاده شد. اگر تا یک دقیقه نیامد، اجازه‌ی اعلان را در تنظیمات مرورگر و سیستم‌عامل ببینید.']);
    }

    public function destroy(Request $request)
    {
        $request->validate(['endpoint' => 'required|string|max:' . PushSubscription::ENDPOINT_MAX_LENGTH]);
        $request->user()->deletePushSubscription($request->input('endpoint'));

        return response()->json(['message' => 'اعلان مرورگر در این مرورگر خاموش شد.']);
    }
}
