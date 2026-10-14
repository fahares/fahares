<?php

namespace App\Http\Controllers\Auth;

use App\Auth\GuestSuggestionLinker;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

/**
 * Email verification. Nothing on the site requires it; a verified email lets the account
 * claim the suggestions sent earlier as a guest with that address.
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request)
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('my.suggestions')
            : view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request)
    {
        $request->fulfill();
        $linked = GuestSuggestionLinker::link($request->user());

        return redirect()->route('my.suggestions')
            ->with('success', trim('ایمیل شما تأیید شد. ' . GuestSuggestionLinker::message($linked)));
    }

    public function send(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return back()->with('info', 'ایمیل شما پیش‌تر تأیید شده است.');
        }
        $request->user()->sendEmailVerificationNotification();

        return back()->with('success', 'پیوند تأیید به ' . $request->user()->email . ' فرستاده شد. صندوق ورودی (و پوشه‌ی هرزنامه) را ببینید.');
    }
}
