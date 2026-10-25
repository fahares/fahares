<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the users who manage accounts that someone signed up (backlog item 25), with a link to the new
 * user's panel page. The phone is shown only to recipients who hold users.contact.
 */
class UserRegistered extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'user.registered';

    public function __construct(public User $user)
    {
        $this->locale('fa');
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? NotificationPreferences::channelsFor($notifiable, self::TYPE) : [];
    }

    /** @return string[] */
    private function lines(object $notifiable): array
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

    private function url(): string
    {
        return route('admin.users.edit', $this->user);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('ثبت‌نام کاربر تازه | فهارس')->greeting('کاربر تازه ثبت‌نام کرد');
        foreach ($this->lines($notifiable) as $line) {
            $mail->line($line);
        }

        return $mail->action('صفحه‌ی کاربر در پنل', $this->url())->salutation('فهارس، فهرستگان نسخ خطی ایران');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => 'ثبت‌نام کاربر تازه: ' . $this->user->name,
            'body' => implode("\n", $this->lines($notifiable)),
            'url' => $this->url(),
        ];
    }
}
