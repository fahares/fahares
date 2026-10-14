<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\NotificationPreferences;
use Illuminate\Http\Request;

/** A signed-in user's in-site notifications and their per-type, per-channel settings. */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->latest()->paginate(30);
        // Shown once as new, then marked read
        $unreadIds = $notifications->getCollection()->whereNull('read_at')->pluck('id')->all();
        $request->user()->unreadNotifications()->whereIn('id', $unreadIds)->update(['read_at' => now()]);

        return view('my.notifications', compact('notifications', 'unreadIds'));
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        $types = NotificationPreferences::typesFor($user);
        $channels = NotificationPreferences::channels();
        $enabled = [];
        foreach ($types as $type => $definition) {
            foreach (array_keys($definition['channels']) as $channel) {
                $enabled[$type][$channel] = NotificationPreferences::enabled($user, $type, $channel);
            }
        }

        return view('my.notification-settings', compact('types', 'channels', 'enabled'));
    }

    public function saveSettings(Request $request)
    {
        $user = $request->user();
        $chosen = (array) $request->input('enabled', []);
        foreach (NotificationPreferences::typesFor($user) as $type => $definition) {
            foreach (array_keys($definition['channels']) as $channel) {
                NotificationPreferences::set($user, $type, $channel, ! empty($chosen[$type][$channel]));
            }
        }

        return back()->with('success', 'تنظیمات اعلان ذخیره شد.');
    }

    /** One-click unsubscribe from an email footer; the link is signed, so no sign-in is needed. */
    public function unsubscribe(User $user, string $type, string $channel)
    {
        abort_unless(array_key_exists($type, NotificationPreferences::types()), 404);
        NotificationPreferences::set($user, $type, $channel, false);

        return view('my.unsubscribed', [
            'label' => NotificationPreferences::types()[$type]['label'],
            'channel' => NotificationPreferences::channels()[$channel] ?? $channel,
        ]);
    }
}
