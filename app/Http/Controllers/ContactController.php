<?php

namespace App\Http\Controllers;

use App\Models\FieldSuggestion;
use App\Notifications\SuggestionNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The «تماس با ما» page and its form (backlog item 173, step 14.431). A message is stored as site
 * feedback of category "contact", so it reaches the panel's review queue, its notifications and its
 * conversation box; a guest's reply goes to the email they gave. Spam: a honeypot field, a minimum
 * time on the page, and the route's rate limit.
 */
class ContactController extends Controller
{
    /** A form sent sooner than this after the page was rendered is taken for a bot */
    public const MIN_SECONDS = 3;

    public function show(Request $request)
    {
        $phones = collect(config('contact.phones', []))->map(function (array $phone) {
            $digits = ltrim($phone['number'], '+');

            return $phone + [
                'digits' => $digits,
                'whatsapp_url' => ! empty($phone['whatsapp']) ? 'https://wa.me/' . $digits : null,
                'telegram_url' => ! empty($phone['telegram']) ? 'https://t.me/+' . $digits : null,
                'eitaa_url' => ! empty($phone['eitaa']) ? 'https://eitaa.com/' . ltrim($phone['eitaa'], '@') : null,
            ];
        });

        return view('pages.contact', [
            'email' => config('contact.email'),
            'phones' => $phones,
            'startedAt' => encrypt(now()->timestamp),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => [$user ? 'nullable' : 'required', 'string', 'max:100'],
            'email' => [$user ? 'nullable' : 'required', 'email', 'max:100'],
            'subject' => 'nullable|string|max:200',
            'message' => 'required|string|min:10|max:5000',
            'website' => 'nullable|max:0',
            'started' => 'required|string',
        ], [
            'name.required' => 'نام خود را بنویسید.',
            'email.required' => 'ایمیل خود را بنویسید تا بتوانیم پاسخ دهیم.',
            'email.email' => 'نشانی ایمیل درست نیست.',
            'message.required' => 'متن پیام را بنویسید.',
            'message.min' => 'متن پیام را کمی کامل‌تر بنویسید.',
            'message.max' => 'متن پیام حداکثر ۵۰۰۰ نویسه است.',
            'website.max' => 'ارسال پذیرفته نشد.',
            'started.required' => 'ارسال پذیرفته نشد. صفحه را دوباره باز کنید.',
        ]);

        try {
            $started = (int) decrypt($validated['started']);
        } catch (\Throwable) {
            $started = 0;
        }
        if (! $started || now()->timestamp - $started < self::MIN_SECONDS) {
            return back()->withInput()->withErrors(['form' => 'ارسال پذیرفته نشد. چند لحظه بعد دوباره بفرستید.']);
        }

        $subject = trim($validated['subject'] ?? '') ?: null;
        $suggestion = FieldSuggestion::create([
            'suggestable_type' => null,
            'suggestable_id' => null,
            'kind' => 'feedback',
            'field_name' => 'contact',
            // The subject heads the text, so the panel shows it without knowing this form
            'suggested_value' => ($subject ? "موضوع: {$subject}\n\n" : '') . trim($validated['message']),
            'payload' => array_filter([
                'url' => '/contact',
                'title' => 'تماس با ما',
                'subject' => $subject,
                'agent' => Str::limit((string) $request->userAgent(), 300, ''),
            ]),
            'user_id' => $user?->id,
            'guest_name' => $user ? $user->name : trim($validated['name']),
            'guest_email' => $user ? $user->email : $validated['email'],
            // A guest wrote to be answered: the reply from the panel goes to their email
            'notify_submitter' => ! $user,
            'status' => 'pending',
        ]);

        SuggestionNotifier::submitted($suggestion);

        return redirect()->route('contact')->with('contact_sent', 'پیام شما رسید. سپاس از نامه‌تان؛ به‌زودی پاسخ می‌دهیم.');
    }
}
