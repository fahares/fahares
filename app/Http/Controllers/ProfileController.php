<?php

namespace App\Http\Controllers;

use App\Support\Phone;
use App\Support\UserAvatar;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The signed-in user's own account page: name, affiliation and an optional phone (backlog items 74 and 75),
 * and their picture (backlog item 66). Also the one-time «تکمیل حساب» page after a first Google sign-in.
 */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return view('my.profile', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $request->user()->update($this->validated($request, withAffiliation: true));

        return back()->with('success', 'مشخصات شما ذخیره شد.');
    }

    /** «نامم همین بماند» on the bar that asks for a Persian name, e.g. for a scholar outside Iran */
    public function keepName(Request $request)
    {
        $request->user()->forceFill(['name_kept_at' => now()])->save();

        return back()->with('success', 'نام شما همین‌گونه می‌ماند. هر وقت خواستید، آن را در «حساب من» تغییر دهید.');
    }

    /** Shown once, after a first Google sign-in with a Latin name; may be skipped. */
    public function complete(Request $request)
    {
        return view('auth.complete', ['user' => $request->user()]);
    }

    public function saveComplete(Request $request)
    {
        $user = $request->user();
        if (! $request->boolean('skip')) {
            $user->update($this->validated($request, withAffiliation: false));
        }

        return redirect()->intended($user->canAccessAdmin() ? route('admin.dashboard') : route('home'))
            ->with('success', "{$user->name}، به فهارس خوش آمدید.");
    }

    private function validated(Request $request, bool $withAffiliation): array
    {
        $validated = $request->validate(array_filter([
            'name' => ['required', 'string', 'max:255'],
            'affiliation' => $withAffiliation ? ['nullable', 'string', 'max:255'] : null,
            'phone' => ['nullable', 'string', 'max:40', Phone::rule()],
        ]), ['name.required' => 'نام و نام خانوادگی الزامی است.']);
        $validated['phone'] = Phone::normalize($validated['phone'] ?? null);

        return $validated;
    }

    public function updateAvatar(Request $request)
    {
        $request->validate(['avatar' => 'required|file|image|mimes:jpg,jpeg,png,webp,gif|max:5120']);

        try {
            UserAvatar::upload($request->user(), $request->file('avatar'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['avatar' => $e->getMessage()]);
        }

        return back()->with('success', 'تصویر شما ذخیره شد.');
    }

    public function destroyAvatar(Request $request)
    {
        UserAvatar::remove($request->user());

        return back()->with('success', 'تصویر برداشته شد و حرف نخست نام شما به‌جای آن نمایش داده می‌شود.');
    }
}
