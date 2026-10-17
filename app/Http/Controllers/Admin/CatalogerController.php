<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cataloger;
use Illuminate\Http\Request;

class CatalogerController extends Controller
{
    public function index()
    {
        $catalogers = Cataloger::orderByDesc('manuscripts_count')->get();
        return view('admin.catalogers.index', compact('catalogers'));
    }

    public function edit(Cataloger $cataloger)
    {
        return view('admin.catalogers.edit', compact('cataloger'));
    }

    public function update(Request $request, Cataloger $cataloger)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'birth_year_solar' => 'nullable|integer',
            'death_year_solar' => 'nullable|integer',
            'birth_year_hijri' => 'nullable|integer',
            'death_year_hijri' => 'nullable|integer',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|image|max:3072',
        ]);

        $oldValues = $cataloger->toArray();

        $cataloger->name = $validated['name'];
        $cataloger->birth_year_solar = $validated['birth_year_solar'] ?? null;
        $cataloger->death_year_solar = $validated['death_year_solar'] ?? null;
        $cataloger->birth_year_hijri = $validated['birth_year_hijri'] ?? null;
        $cataloger->death_year_hijri = $validated['death_year_hijri'] ?? null;
        $cataloger->bio = $validated['bio'] ?? null;

        // Avatars go to the public disk: in production that is the persistent volume, while
        // public/images is rebuilt from git on every deploy and would lose the upload.
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = "cataloger_{$cataloger->id}_" . time() . '.' . $file->extension();
            $cataloger->avatar_path = $file->storeAs('catalogers/avatars', $filename, 'public');
        }

        $cataloger->save();

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Cataloger::class,
            'auditable_id' => $cataloger->id,
            'action' => 'update',
            'old_values' => $oldValues,
            'new_values' => $cataloger->toArray(),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.catalogers.index')
            ->with('success', "مشخصات فهرست‌نگار [{$cataloger->name}] با موفقیت به‌روزرسانی شد.");
    }
}
