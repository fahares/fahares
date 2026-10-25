<?php

namespace App\Observers;

use App\Auth\Permissions;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Notice;
use App\Notifications\NotificationPreferences;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Account changes that are told whatever path made them (backlog item 25): a role or verified-scholar change to
 * the user and, for a role, to the admins; an email or password change to the user, the old email included.
 */
class UserNotices
{
    public function updated(User $user): void
    {
        try {
            if ($user->wasChanged('role')) {
                $this->roleChanged($user, (string) $user->getOriginal('role'));
            } elseif ($user->wasChanged('is_verified_scholar') && $user->is_verified_scholar) {
                $this->notifyUser($user, 'account.role', 'حساب شما تأیید شد', ['حساب شما در فهارس به‌عنوان «پژوهشگر تأییدشده» تأیید شد.'], route('my.suggestions'));
            }
            if ($user->wasChanged('email') || $user->wasChanged('password')) {
                $this->securityChanged($user);
            }
        } catch (\Throwable $e) {
            Log::warning('Account notice failed: ' . $e->getMessage());
        }
    }

    private function roleChanged(User $user, string $old): void
    {
        $labels = Role::options();
        $from = $labels[$old] ?? $old;
        $to = $labels[$user->role] ?? $user->role;
        $by = auth()->user();
        $lines = ["نقش {$user->name} ({$user->email}) از «{$from}» به «{$to}» تغییر کرد.", 'به دست: ' . ($by ? $by->name : 'خط فرمان سرور')];
        if (in_array($user->role, [Permissions::SUPER_ROLE, 'editor'], true)) {
            $lines[] = 'این نقش به پنل مدیریت دسترسی دارد.';
        }
        NotificationPreferences::broadcast('user.role_changed', new Notice('user.role_changed', 'تغییر نقش کاربر', $lines, route('admin.users.edit', $user), 'صفحه‌ی کاربر در پنل'));

        $this->notifyUser($user, 'account.role', 'نقش حساب شما تغییر کرد', ["نقش حساب شما در فهارس از «{$from}» به «{$to}» تغییر کرد."], route('notifications.settings'));
    }

    private function securityChanged(User $user): void
    {
        $what = $user->wasChanged('email') ? 'ایمیل' : 'گذرواژه';
        $lines = ["{$what} حساب شما در فهارس در " . \App\Support\Jalali::format(now()) . ' تغییر کرد.', 'اگر این کار را شما نکرده‌اید، بی‌درنگ گذرواژه را بازیابی کنید و به ما خبر دهید.'];
        $notice = new Notice('account.security', "تغییر {$what} حساب", $lines, route('password.request'), 'بازیابی گذرواژه');
        $user->notify($notice);
        // The old address hears of an email change too: it is the one an intruder would have replaced
        $old = $user->getOriginal('email');
        if ($user->wasChanged('email') && $old) {
            Notification::route('mail', $old)->notify($notice);
        }
    }

    private function notifyUser(User $user, string $type, string $title, array $lines, string $url): void
    {
        if ($user->id !== auth()->id()) {
            $user->notify(new Notice($type, $title, $lines, $url));
        }
    }
}
