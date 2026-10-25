<?php

namespace App\Http\Controllers\Auth;

use App\Auth\GuestSuggestionLinker;
use App\Auth\HeldCredit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\IntendedUrl;
use App\Support\PersonName;
use App\Support\UserAvatar;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Sign in with Google. Google has verified the address, so an existing account with the same
 * email is linked rather than duplicated, and the email counts as verified.
 */
class GoogleController extends Controller
{
    public static function enabled(): bool
    {
        return (bool) config('services.google.client_id');
    }

    public function redirect()
    {
        abort_unless(self::enabled(), 404);
        IntendedUrl::remember(request());

        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        abort_unless(self::enabled(), 404);

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google sign-in failed: ' . $e->getMessage());

            return redirect()->route('login')->with('warning', 'ورود با گوگل انجام نشد. لطفاً دوباره تلاش کنید.');
        }

        $email = mb_strtolower(trim((string) $google->getEmail()));
        if ($email === '' || ($google->user['email_verified'] ?? true) === false) {
            return redirect()->route('login')->with('warning', 'حساب گوگل شما ایمیل تأییدشده ندارد.');
        }

        $user = User::where('google_id', $google->getId())->first()
            ?? User::whereRaw('LOWER(email) = ?', [$email])->first();
        $created = false;

        if (! $user) {
            $user = User::create([
                'name' => $google->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'google_id' => $google->getId(),
                // Never used to sign in; the account signs in with Google
                'password' => Str::random(40),
                'role' => 'user',
                'is_verified_scholar' => false,
            ]);
            $created = true;
        } elseif (! $user->google_id) {
            $user->forceFill(['google_id' => $google->getId()])->save();
        }

        // A local copy of the Google picture, unless the user uploaded or removed one (backlog item 66)
        UserAvatar::syncGoogle($user, $google->getAvatar());

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
        $linked = GuestSuggestionLinker::link($user);
        HeldCredit::release($user);

        Auth::login($user, true);
        request()->session()->regenerate();

        // A new account with a Latin Google name is asked once for its Persian name and an optional phone (backlog item 75)
        if ($created && PersonName::isLatinOnly($user->name)) {
            return redirect()->route('account.complete');
        }

        $welcome = $created ? "حساب شما با گوگل ساخته شد. {$user->name}، به فهارس خوش آمدید." : "{$user->name}، خوش آمدید.";

        return redirect()->intended($user->canAccessAdmin() ? route('admin.dashboard') : route('home'))
            ->with('success', trim($welcome . ' ' . GuestSuggestionLinker::message($linked)));
    }
}
