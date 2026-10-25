<?php

namespace App\Http\Controllers;

use App\Models\FieldSuggestion;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Http\Request;

/**
 * A signed-in user's own suggestions: their review status, the standing of their readings,
 * the reviewer's public note, and the name shown beside each approved correction.
 * Only suggestions sent while signed in are listed; guest ones wait for verified emails.
 */
class MySuggestionsController extends Controller
{
    public function index(Request $request)
    {
        $query = FieldSuggestion::where('user_id', $request->user()->id);

        $stats = [
            'total' => (clone $query)->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'adopted' => (clone $query)->where('status', 'approved')->where('standing', 'adopted')->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
        ];

        $suggestions = $query->with(['suggestable', 'supersededBy'])->latest('id')->paginate(25);

        return view('my.suggestions', compact('suggestions', 'stats'));
    }

    public function credit(Request $request, FieldSuggestion $suggestion)
    {
        abort_unless($suggestion->user_id === $request->user()->id, 403);
        $validated = $request->validate(['credit_name' => 'nullable|string|max:150']);
        $suggestion->update(\App\Auth\HeldCredit::columns($request->user(), $validated['credit_name'] ?? null));

        return back()->with('success', match (true) {
            (bool) $suggestion->credit_name => "نام «{$suggestion->credit_name}» برای این پیشنهاد ثبت شد.",
            (bool) $suggestion->held_credit_name => "نام «{$suggestion->held_credit_name}» ثبت شد و پس از تأیید ایمیل شما منتشر می‌شود.",
            default => 'نام شما از کنار این پیشنهاد برداشته شد.',
        });
    }

    /** Public page of the record a suggestion is about, or null once it is gone (e.g. merged away). */
    public static function recordUrl(FieldSuggestion $s): ?string
    {
        if (! $s->suggestable) {
            return null;
        }

        return SuggestionRegistry::describe($s->suggestable)['url'] ?? null;
    }
}
