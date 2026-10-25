<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A one-off account notice, inside the site and by email, to users whose name is in Latin letters only:
 * please write it in Persian, since it is published beside their corrections (backlog item 75).
 * Sent by `fahares:ask-persian-names`, not tied to an event, so it is not a type in config/notifications.php.
 */
class PersianNameRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'account.persian_name';

    public function __construct()
    {
        $this->locale('fa');
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return string[] */
    private function lines(object $notifiable): array
    {
        return [
            "نام حساب شما در فهارس «{$notifiable->name}» است و با حروف لاتین نوشته شده است.",
            'این نام کنار اصلاحات و یادداشت‌هایی که می‌فرستید منتشر می‌شود. لطفاً نام و نام خانوادگی خود را در صفحه‌ی «حساب من» به فارسی بنویسید.',
            'اگر می‌خواهید نامتان به همین شکل بماند، در نوار بالای صفحه‌های سایت «نامم همین بماند» را بزنید.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('لطفاً نام خود را به فارسی بنویسید | فهارس')->greeting('نام شما در فهارس');
        foreach ($this->lines($notifiable) as $line) {
            $mail->line($line);
        }

        return $mail->action('ویرایش نام', route('profile'))->salutation('فهارس، فهرستگان نسخ خطی ایران');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => 'لطفاً نام خود را به فارسی بنویسید',
            'body' => implode("\n", $this->lines($notifiable)),
            'url' => route('profile'),
        ];
    }
}
