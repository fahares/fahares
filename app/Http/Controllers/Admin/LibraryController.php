<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Library;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LibraryController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('q');
        $city = $request->query('city');

        $query = Library::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%");
            });
        }

        if ($city) {
            $query->where('city', $city);
        }

        $libraries = $query->orderByDesc('manuscripts_count')->paginate(25)->withQueryString();
        $cities = Library::distinct()->pluck('city')->filter()->sort()->values();

        return view('admin.libraries.index', compact('libraries', 'search', 'city', 'cities'));
    }

    public function edit(Library $library)
    {
        return view('admin.libraries.edit', compact('library'));
    }

    public function update(Request $request, Library $library)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'full_name' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'country' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        $oldValues = $library->toArray();

        $library->update([
            'name' => $validated['name'],
            'full_name' => $validated['full_name'] ?? null,
            'city' => $validated['city'],
            'country' => $validated['country'],
            'description' => $validated['description'] ?? null,
        ]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Library::class,
            'auditable_id' => $library->id,
            'action' => 'update',
            'old_values' => $oldValues,
            'new_values' => $library->toArray(),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.libraries.index')
            ->with('success', "اطلاعات کتابخانه [{$library->name}] با موفقیت به‌روزرسانی شد.");
    }
}
