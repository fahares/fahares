<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Support\AnnouncementTelegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The admin pages for the site's public notices: write one, switch it on for a number of days,
 * and see how many visitors saw it and followed its link.
 */
class AnnouncementController extends Controller
{
    public function index()
    {
        $announcements = Announcement::query()->orderByDesc('id')->paginate(30);

        return view('admin.announcements.index', compact('announcements'));
    }

    public function create()
    {
        return view('admin.announcements.edit', [
            'announcement' => new Announcement(['style' => 'card', 'audience' => 'all', 'days' => Announcement::DEFAULT_DAYS, 'telegram' => true]),
        ]);
    }

    public function store(Request $request)
    {
        $announcement = new Announcement(['user_id' => auth()->id()]);
        $this->fill($request, $announcement);
        $this->audit($request, $announcement->id, 'create', null, $announcement->toArray());

        return $this->withTelegram(redirect()->route('admin.announcements.edit', $announcement), $announcement, $announcement->is_active
            ? 'اعلان ذخیره و روشن شد.'
            : 'اعلان ذخیره شد. پیش‌نمایش را ببینید و هر وقت آماده بود روشنش کنید.');
    }

    public function edit(Announcement $announcement)
    {
        return view('admin.announcements.edit', compact('announcement'));
    }

    public function update(Request $request, Announcement $announcement)
    {
        $old = $announcement->toArray();
        $this->fill($request, $announcement);
        $this->audit($request, $announcement->id, 'update', $old, $announcement->toArray());

        return $this->withTelegram(back(), $announcement, 'تغییرات ذخیره شد.');
    }

    /** Sends a notice's post to the channel again after a failure. */
    public function telegram(Announcement $announcement)
    {
        return $this->withTelegram(back(), $announcement, 'چیزی برای فرستادن به تلگرام نبود.');
    }

    public function telegramSettings(Request $request)
    {
        $data = $request->validate([
            'channel' => ['nullable', 'string', 'max:64', 'regex:/^(@?[A-Za-z][A-Za-z0-9_]{3,}|-?\d+)$/'],
            'footer' => 'nullable|string|max:200',
        ], ['channel.regex' => 'نام کانال را مانند ‎@fahares_net‎ بنویسید.']);
        // An empty string, not null: null would bring the default back
        SiteSetting::put('telegram.channel', (string) ($data['channel'] ?? ''));
        SiteSetting::put('telegram.footer', (string) ($data['footer'] ?? ''));

        return back()->with('success', 'تنظیمات تلگرام ذخیره شد.');
    }

    /** Posts or edits the channel's post and tells the manager what happened there. */
    private function withTelegram($response, Announcement $announcement, string $message)
    {
        if (\App\Jobs\SendAnnouncementNotice::forFirstRun($announcement)) {
            $message .= ' در اعلان‌های اعضا هم گذاشته شد.';
        }
        return match (AnnouncementTelegram::sync($announcement)) {
            'sent' => $response->with('success', $message . ' پست آن به کانال تلگرام رفت.'),
            'edited' => $response->with('success', $message . ' پست کانال تلگرام هم ویرایش شد.'),
            'failed' => $response->with('success', $message)->with('error', 'به تلگرام نرفت: ' . $announcement->telegram_error),
            default => $response->with('success', $message),
        };
    }

    public function destroy(Request $request, Announcement $announcement)
    {
        $old = $announcement->toArray();
        $announcement->delete();
        $announcement->deleteFiles();
        $this->audit($request, $old['id'], 'announcement_delete', $old, null);

        return redirect()->route('admin.announcements.index')->with('success', "اعلان «{$old['title']}» حذف شد.");
    }

    private function fill(Request $request, Announcement $announcement): void
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'nullable|string|max:600',
            // A path of this site or a secure address; nothing else (javascript:, //host) can become the button's target
            'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(/(?!/)|https://)\S*$#'],
            'link_label' => 'nullable|string|max:40',
            'style' => 'required|in:' . implode(',', array_keys(Announcement::STYLES)),
            'audience' => 'required|in:' . implode(',', array_keys(Announcement::AUDIENCES)),
            'days' => 'required|integer|min:1|max:365',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'telegram_text' => 'nullable|string|max:3500',
        ], [
            'link_url.regex' => 'پیوند باید نشانی صفحه‌ای از همین سایت باشد (مانند ‎/posts/12‎) یا با https:// آغاز شود.',
            'image.max' => 'حجم تصویر حداکثر ۲ مگابایت است.',
            'days.max' => 'مدت نمایش حداکثر ۳۶۵ روز است.',
        ]);
        unset($data['image']);

        // The channel is public, so only a notice for everyone goes there
        $data['telegram'] = $request->boolean('telegram') && $data['audience'] === 'all';
        if ($data['telegram']) {
            $withPicture = $announcement->telegram_message_id
                ? $announcement->telegram_photo
                : $request->hasFile('image') || ($announcement->image_path && ! $request->boolean('remove_image'));
            $limit = $withPicture ? AnnouncementTelegram::CAPTION_LIMIT : AnnouncementTelegram::TEXT_LIMIT;
            $length = AnnouncementTelegram::length(AnnouncementTelegram::compose($data['title'], $data['body'] ?? null, $data['telegram_text'] ?? null, $data['link_url'] ?? null, $data['link_label'] ?? null));
            if ($length > $limit) {
                throw \Illuminate\Validation\ValidationException::withMessages(['telegram_text' => 'پست تلگرام ' . \App\Support\Fa::num($length) . ' نویسه است و حد آن ' . ($withPicture ? 'با تصویر ' : '') . \App\Support\Fa::num($limit) . ' نویسه است. متن را کوتاه کنید.']);
            }
        }

        $wasRunning = $announcement->exists && $announcement->isRunning();
        $data['is_active'] = $request->boolean('is_active');
        // Switching on begins a new run from now; saving a running notice keeps its start
        if ($data['is_active'] && ! $wasRunning) {
            $data['starts_at'] = now();
        }
        $start = $data['starts_at'] ?? $announcement->starts_at;
        if ($start) {
            $data['ends_at'] = $start->copy()->addDays((int) $data['days']);
        }

        $announcement->fill($data)->save();

        $oldImage = $announcement->image_path;
        if ($request->hasFile('image')) {
            $announcement->update(['image_path' => $request->file('image')->store("announcements/{$announcement->id}", 'public')]);
        } elseif ($request->boolean('remove_image')) {
            $announcement->update(['image_path' => null]);
        }
        if ($oldImage && $oldImage !== $announcement->image_path) {
            Storage::disk('public')->delete($oldImage);
        }
    }

    private function audit(Request $request, int $id, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Announcement::class,
            'auditable_id' => $id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
        ]);
    }
}
