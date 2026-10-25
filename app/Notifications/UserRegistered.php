<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Tells the users who manage accounts that someone signed up (backlog item 25), with a link to the new
 * user's panel page. The phone is shown only to recipients who hold users.contact.
 */
class UserRegistered extends SiteNotification
{
    public const TYPE = 'user.registered';

    public function __construct(public User $user)
    {
        parent::__construct();
    }

    protected function title(): string
    {
        return 'ثبت‌نام کاربر تازه: ' . $this->user->name;
    }

    protected function lines(object $notifiable): array
    {
        $lines = [
            'نام: ' . $this->user->name,
            'ایمیل: ' . $this->user->email,
            'روش ثبت‌نام: ' . ($this->user->google_id ? 'ورود با گوگل' : 'فرم ثبت‌نام'),
        ];
        if ($this->user->affiliation) {
            $lines[] = 'وابستگی سازمانی: ' . $this->user->affiliation;
        }
        if ($this->user->phone && $notifiable instanceof User && $notifiable->hasPermission('users.contact')) {
            $lines[] = 'شماره‌ی تماس: ' . $this->user->phone;
        }

        return $lines;
    }

    protected function url(object $notifiable): string
    {
        return route('admin.users.edit', $this->user);
    }

    protected function actionLabel(): string
    {
        return 'صفحه‌ی کاربر در پنل';
    }
}
