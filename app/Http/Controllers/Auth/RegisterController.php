<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
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

        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'affiliation' => ['nullable', 'string', 'max:255'],
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
            'role' => 'user',
            'is_verified_scholar' => false,
        ]);

        Auth::login($user);

        return redirect()->intended(route('home'))
            ->with('success', "ثبت‌نام با موفقیت انجام شد. پژوهشگر گرامی {$user->name}، به پایگاه فهارس خوش آمدید.");
    }
}
