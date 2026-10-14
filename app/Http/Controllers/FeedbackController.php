<?php

namespace App\Http\Controllers;

use App\Models\FieldSuggestion;
use App\Notifications\SuggestionNotifier;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Feedback about the site from any page: a bug, a data error on a page without a record form,
 * or an idea. Stored as a suggestion of kind "feedback" without a record, so it shares the review
 * queue, notifications and "my suggestions"; it is never shown on the public site.
 */
class FeedbackController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', Rule::in(array_keys(SuggestionRegistry::FEEDBACK_CATEGORIES))],
            'message' => 'required|string|min:5|max:5000',
            'page_url' => 'nullable|string|max:2000',
            'page_title' => 'nullable|string|max:300',
            'guest_name' => 'nullable|string|max:100',
            'guest_email' => 'nullable|email|max:100',
            'notify_submitter' => 'nullable|boolean',
            'files' => 'nullable|array|max:2',
            'files.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'website' => 'nullable|max:0',
        ], [
            'message.required' => 'متن گزارش را بنویسید.',
            'message.min' => 'متن گزارش را کمی کامل‌تر بنویسید.',
            'files.*.max' => 'حجم هر تصویر حداکثر ۲ مگابایت است.',
            'files.*.image' => 'فقط تصویر (JPG، PNG یا WebP) پذیرفته می‌شود.',
            'website.max' => 'ارسال پذیرفته نشد.',
        ]);

        $user = $request->user();
        $files = $request->file('files', []);
        if ($files && ! $user) {
            throw ValidationException::withMessages(['files' => 'برای پیوست تصویر وارد حساب کاربری شوید.']);
        }

        // Only a path on this site is kept, never an outside address
        $path = parse_url((string) ($validated['page_url'] ?? ''), PHP_URL_PATH) ?: '/';
        $query = parse_url((string) ($validated['page_url'] ?? ''), PHP_URL_QUERY);
        $payload = array_filter([
            'url' => $path . ($query ? '?' . $query : ''),
            'title' => trim($validated['page_title'] ?? '') ?: null,
            'agent' => Str::limit((string) $request->userAgent(), 300, ''),
        ]);

        $suggestion = FieldSuggestion::create([
            'suggestable_type' => null,
            'suggestable_id' => null,
            'kind' => 'feedback',
            'field_name' => $validated['category'],
            'suggested_value' => trim($validated['message']),
            'payload' => $payload,
            'user_id' => $user?->id,
            'guest_name' => $user ? $user->name : ($validated['guest_name'] ?? 'بازدیدکننده'),
            'guest_email' => $user ? $user->email : ($validated['guest_email'] ?? null),
            'notify_submitter' => ! $user && ! empty($validated['guest_email']) && $request->boolean('notify_submitter'),
            'status' => 'pending',
        ]);

        if ($files) {
            $stored = [];
            foreach ($files as $file) {
                $stored[] = $file->storeAs("feedback/{$suggestion->id}", Str::random(40) . '.' . $file->extension(), 'public');
            }
            $suggestion->update(['payload' => $payload + ['files' => $stored]]);
        }

        SuggestionNotifier::submitted($suggestion);

        return response()->json(['message' => 'گزارش شما ثبت شد. سپاس از اینکه به بهتر شدن فهارس کمک می‌کنید.']);
    }
}
