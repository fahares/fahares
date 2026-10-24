<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\IntendedUrl;
use App\Support\Phone;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    public function showRegistrationForm()
    {
        if (Auth::check()) {
            return redirect()->route('home');
        }
        IntendedUrl::remember(request());

        return view('auth.register');
    }

    public function register(Request $request)
    {
        // The short form in the suggestion desk's sign-in dialog has no repeat field; its password can be shown instead
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => array_merge(['required', 'string', 'min:8'], $request->has('password_confirmation') ? ['confirmed'] : []),
            'affiliation' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', Phone::rule()],
        ], [
            'name.required' => 'نام و نام خانوادگی الزامی است.',
            'email.required' => 'نشانی رایانامه الزامی است.',
            'email.email' => 'فرمت نشانی رایانامه نامعتبر است.',
            'email.unique' => 'این نشانی رایانامه پیش‌تر در سامانه ثبت شده است.',
            'password.required' => 'گذرواژه الزامی است.',
            'password.min' => 'طول گذرواژه باید حداقل ۸ نویسه باشد.',
            'password.confirmed' => 'تکرار گذرواژه با گذرواژه اصلی همخوانی ندارد.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'affiliation' => $validated['affiliation'] ?? null,
            'phone' => Phone::normalize($validated['phone'] ?? null),
            'role' => 'user',
            'is_verified_scholar' => false,
        ]);

        // Sends the verification link (framework listener for Registered)
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        IntendedUrl::remember($request);

        $request->session()->flash('success', "ثبت‌نام با موفقیت انجام شد. پژوهشگر گرامی {$user->name}، به پایگاه فهارس خوش آمدید. پیوند تأیید ایمیل به {$user->email} فرستاده شد؛ پس از تأیید آن، نام شما کنار اصلاحاتتان منتشر می‌شود.");
        $target = redirect()->intended(route('home'));

        // The sign-in dialog of the suggestion desk posts here with fetch and follows the returned address
        return $request->expectsJson() ? response()->json(['redirect' => $target->getTargetUrl()]) : $target;
    }
}
