<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AuditLog;
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
            'announcement' => new Announcement(['style' => 'card', 'audience' => 'all', 'days' => Announcement::DEFAULT_DAYS]),
        ]);
    }

    public function store(Request $request)
    {
        $announcement = new Announcement(['user_id' => auth()->id()]);
        $this->fill($request, $announcement);
        $this->audit($request, $announcement->id, 'create', null, $announcement->toArray());

        return redirect()->route('admin.announcements.edit', $announcement)->with('success', $announcement->is_active
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

        return back()->with('success', 'تغییرات ذخیره شد.');
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
        ], [
            'link_url.regex' => 'پیوند باید نشانی صفحه‌ای از همین سایت باشد (مانند ‎/posts/12‎) یا با https:// آغاز شود.',
            'image.max' => 'حجم تصویر حداکثر ۲ مگابایت است.',
            'days.max' => 'مدت نمایش حداکثر ۳۶۵ روز است.',
        ]);
        unset($data['image']);

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
