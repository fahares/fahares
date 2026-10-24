<?php

namespace App\Http\Controllers\Admin;

use App\Auth\Permissions;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccessMatrixChanged;
use App\Notifications\NotificationPreferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The access matrix page (backlog item 65): which role may use which section and action of the admin panel.
 * The admin column is shown but cannot change; each change is logged per role and announced to the admins.
 */
class RoleController extends Controller
{
    public function index()
    {
        return view('admin.roles.index', [
            'roles' => Role::orderBy('sort_order')->get(),
            'counts' => User::selectRaw('role, count(*) c')->groupBy('role')->pluck('c', 'role'),
            'groups' => Permissions::grouped(),
            'matrix' => Permissions::matrix(),
            'defaults' => Permissions::defaults(),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'grants' => 'nullable|array',
            'grants.*' => 'array',
            'grants.*.*' => 'string',
        ]);

        return $this->apply($request, $validated['grants'] ?? [], 'دسترسی نقش‌ها ذخیره شد.');
    }

    public function reset(Request $request)
    {
        return $this->apply($request, Permissions::defaults(), 'دسترسی نقش‌ها به مقدار پیش‌فرض بازگشت.');
    }

    private function apply(Request $request, array $grants, string $message)
    {
        $before = Permissions::matrix();
        $changes = Permissions::save($grants);
        if (! $changes) {
            return back()->with('info', 'تغییری در دسترسی‌ها داده نشد.');
        }

        $roles = Role::whereIn('key', array_keys($changes))->get()->keyBy('key');
        $after = Permissions::matrix();
        foreach ($changes as $key => $change) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'auditable_type' => Role::class,
                'auditable_id' => $roles[$key]->id,
                'action' => 'permissions',
                'old_values' => ['role' => $key, 'permissions' => $before[$key] ?? []],
                'new_values' => ['role' => $key, 'permissions' => $after[$key] ?? [], 'added' => $change['added'], 'removed' => $change['removed']],
                'ip_address' => $request->ip(),
            ]);
        }

        try {
            $labelled = collect($changes)->mapWithKeys(fn ($change, $key) => [$roles[$key]->label => $change])->all();
            Notification::send(
                NotificationPreferences::recipients(AccessMatrixChanged::TYPE)->reject(fn ($u) => $u->id === $request->user()->id),
                new AccessMatrixChanged($request->user()->name, $labelled),
            );
        } catch (\Throwable $e) {
            Log::warning('Access matrix notification failed: ' . $e->getMessage());
        }

        return redirect()->route('admin.roles.index')->with('success', $message);
    }
}
