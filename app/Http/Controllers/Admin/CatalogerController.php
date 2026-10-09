<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cataloger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

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

        // Handle Avatar Upload
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $dir = public_path('images/catalogers/avatars');
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            $filename = "cataloger_{$cataloger->id}_" . time() . '.' . $file->getClientOriginalExtension();
            $file->move($dir, $filename);
            $cataloger->avatar_path = "catalogers/avatars/{$filename}";
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
