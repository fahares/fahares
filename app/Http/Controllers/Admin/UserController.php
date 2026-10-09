<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->query('role');
        $query = User::query();

        if ($role) {
            $query->where('role', $role);
        }

        $users = $query->latest('id')->paginate(25)->withQueryString();

        return view('admin.users.index', compact('users', 'role'));
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|string|in:admin,editor,researcher,user',
            'affiliation' => 'nullable|string|max:255',
            'is_verified_scholar' => 'boolean',
        ]);

        $oldValues = $user->toArray();

        $user->update([
            'name' => $validated['name'],
            'role' => $validated['role'],
            'affiliation' => $validated['affiliation'] ?? null,
            'is_verified_scholar' => $request->boolean('is_verified_scholar'),
        ]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'action' => 'update',
            'old_values' => $oldValues,
            'new_values' => $user->toArray(),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "مشخصات و سطح دسترسی کاربر [{$user->name}] با موفقیت به‌روزرسانی شد.");
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'امکان حذف حساب کاربری جاری خودتان وجود ندارد.');
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'action' => 'delete',
            'old_values' => $user->toArray(),
            'ip_address' => request()->ip(),
        ]);

        $user->delete();

        return back()->with('info', "کاربر [{$user->name}] با موفقیت حذف شد.");
    }
}
