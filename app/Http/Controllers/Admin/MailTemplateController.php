<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The frame every email of the site shares: the subtitle under the logo, the logo itself, the closing
 * salutation and the footer. The body of each email stays in its notification.
 */
class MailTemplateController extends Controller
{
    public function index()
    {
        $settings = collect(SiteSetting::keys('mail'))->mapWithKeys(fn ($key) => [$key => [
            'label' => SiteSetting::LABELS[$key],
            'value' => SiteSetting::get($key),
            'default' => SiteSetting::DEFAULTS[$key],
        ]]);

        return view('admin.mail.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'mail__subtitle' => 'nullable|string|max:200',
            'mail__salutation' => 'nullable|string|max:200',
            'mail__footer' => 'nullable|string|max:500',
        ]);
        $validated['mail__show_logo'] = $request->boolean('mail__show_logo') ? null : '0';

        $old = [];
        $new = [];
        foreach (SiteSetting::keys('mail') as $key) {
            $value = trim((string) ($validated[str_replace('.', '__', $key)] ?? ''));
            $value = $value === '' ? null : $value;
            $current = SiteSetting::find($key)?->value;
            if ($current !== $value) {
                $old[$key] = $current;
                $new[$key] = $value;
                SiteSetting::put($key, $value);
            }
        }

        if ($new) {
            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => SiteSetting::class,
                'auditable_id' => 0,
                'action' => 'mail_template',
                'old_values' => $old,
                'new_values' => $new,
                'ip_address' => $request->ip(),
            ]);
        }

        return back()->with('success', 'قالب ایمیل‌ها ذخیره شد.');
    }

    /** A sample email in the saved frame, for the page's preview */
    public function preview()
    {
        return (new MailMessage)
            ->greeting('نمونه‌ی ایمیل فهارس')
            ->line('این یک ایمیل نمونه است تا سربرگ، امضا و پانویس ایمیل‌های سایت را پیش از فرستادن ببینید.')
            ->line('متن هر ایمیل از اعلان مربوط به آن می‌آید و این‌جا فقط قاب مشترک ایمیل‌ها تنظیم می‌شود.')
            ->action('رفتن به فهارس', url('/'))
            ->salutation(SiteSetting::get('mail.salutation'))
            ->render();
    }
}
