<?php

namespace App\Http\Controllers;

use App\Support\UserAvatar;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The signed-in user's own account page: for now, their picture (backlog item 66). */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return view('my.profile', ['user' => $request->user()]);
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
