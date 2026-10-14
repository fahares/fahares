<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** "Forgot password": a reset link by email, then a new password. */
class PasswordResetController extends Controller
{
    public function request()
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request)
    {
        $request->validate(['email' => 'required|email'], ['email.required' => 'نشانی ایمیل را وارد کنید.', 'email.email' => 'فرمت نشانی ایمیل نامعتبر است.']);
        Password::sendResetLink(['email' => mb_strtolower(trim($request->input('email')))]);

        // The same answer whether or not the address has an account, so the form cannot be used to probe emails
        return back()->with('success', 'اگر حسابی با این ایمیل وجود داشته باشد، پیوند بازیابی گذرواژه به آن فرستاده شد. صندوق ورودی (و پوشه‌ی هرزنامه) را ببینید.');
    }

    public function reset(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'password.min' => 'طول گذرواژه باید حداقل ۸ نویسه باشد.',
            'password.confirmed' => 'تکرار گذرواژه با گذرواژه همخوانی ندارد.',
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('info', 'گذرواژه‌ی شما تغییر کرد. اکنون با گذرواژه‌ی تازه وارد شوید.')
            : back()->withInput($request->only('email'))->withErrors(['email' => 'پیوند بازیابی نامعتبر است یا منقضی شده. دوباره درخواست دهید.']);
    }
}
