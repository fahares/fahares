<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::check()) {
            if (Auth::user()->canAccessAdmin()) {
                return redirect()->route('admin.dashboard');
            }
            return redirect()->route('home');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'وارد کردن نشانی رایانامه الزامی است.',
            'email.email' => 'قالب نشانی رایانامه نامعتبر است.',
            'password.required' => 'وارد کردن گذرواژه الزامی است.',
        ]);

        $throttleKey = Str::transliterate(Str::lower($request->input('email')).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'email' => ["دفعات تلاش ناموفق بیش از حد مجاز بوده است. لطفاً {$seconds} ثانیه دیگر مجدداً تلاش نمایید."],
            ]);
        }

        $remember = $request->boolean('remember');

        if (Auth::attempt($credentials, $remember)) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            /** @var \App\Models\User $user */
            $user = Auth::user();

            if ($user->canAccessAdmin() && ! session()->has('url.intended')) {
                return redirect()->route('admin.dashboard')
                    ->with('success', "خوش آمدید جناب {$user->name}. به پنل مدیریت وارد شدید.");
            }

            return redirect()->intended(route('home'))
                ->with('success', "خوش آمدید {$user->name}. ورود با موفقیت انجام شد.");
        }

        RateLimiter::hit($throttleKey, 60);

        return back()->withErrors([
            'email' => 'نشانی رایانامه یا گذرواژه واردشده با اطلاعات سامانه همخوانی ندارد.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')
            ->with('info', 'با موفقیت از سامانه خارج شدید.');
    }
}
