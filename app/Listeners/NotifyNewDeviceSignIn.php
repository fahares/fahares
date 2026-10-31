<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\Notice;
use App\Support\Jalali;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A panel user who signs in from a browser not seen before is told by email (backlog item 25). A browser is
 * known by a random token in a long-lived cookie; the first browser an account is ever seen on is not announced.
 * A sign-in made on the server itself (tinker, a smoke check after a deploy) has no browser and is not tracked.
 */
class NotifyNewDeviceSignIn
{
    public const COOKIE = 'fahares_device';

    public function handle(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || ! $user->hasPermission('panel.access')) {
            return;
        }
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }
        try {
            $request = request();
            $token = (string) $request->cookie(self::COOKIE);
            $known = $token !== '' ? DB::table('known_devices')->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->first() : null;
            if ($known) {
                DB::table('known_devices')->where('id', $known->id)->update(['last_seen_at' => now(), 'ip_address' => $request->ip()]);

                return;
            }

            $first = ! DB::table('known_devices')->where('user_id', $user->id)->exists();
            // Requests a browser sends together before the first one's cookie lands each arrive without it
            $sameBrowser = DB::table('known_devices')->where('user_id', $user->id)
                ->where('user_agent', Str::limit((string) $request->userAgent(), 250, ''))
                ->where('created_at', '>=', now()->subMinute())->exists();
            $token = Str::random(48);
            DB::table('known_devices')->insert([
                'user_id' => $user->id, 'token_hash' => hash('sha256', $token),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''), 'ip_address' => $request->ip(),
                'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Cookie::queue(self::COOKIE, $token, 60 * 24 * 365 * 5);

            if (! $first && ! $sameBrowser) {
                $user->notify(new Notice('account.new_device', 'ورود به حساب شما از دستگاه تازه', [
                    'زمان: ' . Jalali::format(now(), 'datetime'),
                    'نشانی IP: ' . $request->ip(),
                    'مرورگر: ' . Str::limit((string) $request->userAgent(), 160),
                    'اگر این ورود از شما نبوده است، بی‌درنگ گذرواژه را عوض کنید.',
                ], route('password.request'), 'تغییر گذرواژه'));
            }
        } catch (\Throwable $e) {
            Log::warning('New device notice failed: ' . $e->getMessage());
        }
    }
}
