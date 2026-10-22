<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Page;
use Illuminate\Http\Request;

/**
 * Editing the fixed pages of the site, such as «درباره ما».
 */
class PageController extends Controller
{
    public function edit(Page $page)
    {
        return view('admin.pages.edit', compact('page'));
    }

    public function update(Request $request, Page $page)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string|max:200000',
        ]);

        $old = $page->only(['title', 'body']);
        $page->fill($data);

        if ($page->isDirty()) {
            $page->user_id = auth()->id();
            $page->save();

            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => Page::class,
                'auditable_id' => $page->id,
                'action' => 'update',
                'old_values' => $old,
                'new_values' => $page->only(['title', 'body']),
                'ip_address' => $request->ip(),
            ]);
        }

        return back()->with('success', "صفحه‌ی «{$page->title}» ذخیره شد.");
    }
}
