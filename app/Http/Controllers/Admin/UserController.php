<?php

namespace App\Http\Controllers\Admin;

use App\Auth\Permissions;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'role' => ['required', 'string', Rule::exists('roles', 'key')],
            'affiliation' => 'nullable|string|max:255',
            'is_verified_scholar' => 'boolean',
        ]);

        // Only an admin makes or unmakes an admin, even if the matrix lets another role manage users
        abort_if(($user->role === Permissions::SUPER_ROLE || $validated['role'] === Permissions::SUPER_ROLE) && ! auth()->user()->isAdmin(), 403, 'تعیین یا برداشتن نقش مدیر کل فقط با مدیر کل است.');

        // Nobody demotes themselves, and the last admin stays, so the panel always has someone who manages it
        if ($user->role === Permissions::SUPER_ROLE && $validated['role'] !== Permissions::SUPER_ROLE) {
            if ($user->id === auth()->id()) {
                return back()->withInput()->with('error', 'نقش مدیر کل را نمی‌توانید از حساب خودتان بردارید.');
            }
            if ($this->isLastAdmin($user)) {
                return back()->withInput()->with('error', 'این تنها مدیر کل سایت است و نقشش را نمی‌توان تغییر داد.');
            }
        }

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
        abort_if($user->role === Permissions::SUPER_ROLE && ! auth()->user()->isAdmin(), 403, 'حذف مدیر کل فقط با مدیر کل است.');
        if ($user->role === Permissions::SUPER_ROLE && $this->isLastAdmin($user)) {
            return back()->with('error', 'این تنها مدیر کل سایت است و حذف نمی‌شود.');
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

    private function isLastAdmin(User $user): bool
    {
        return ! User::where('role', Permissions::SUPER_ROLE)->whereKeyNot($user->id)->exists();
    }
}
