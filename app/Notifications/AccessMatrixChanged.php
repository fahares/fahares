<?php

namespace App\Notifications;

use App\Auth\Permissions;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the admins that someone changed the access matrix (backlog item 65), with what was added and removed per role. */
class AccessMatrixChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'permissions.changed';

    /** @param  array<string, array{added: string[], removed: string[]}>  $changes  role label => keys */
    public function __construct(public string $by, public array $changes)
    {
        $this->locale('fa');
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? NotificationPreferences::channelsFor($notifiable, self::TYPE) : [];
    }

    /** @return string[] */
    private function lines(): array
    {
        $lines = ["{$this->by} دسترسی نقش‌ها را در پنل مدیریت تغییر داد."];
        foreach ($this->changes as $role => $change) {
            $parts = [];
            if ($change['added']) {
                $parts[] = 'افزوده: ' . implode('، ', array_map([Permissions::class, 'label'], $change['added']));
            }
            if ($change['removed']) {
                $parts[] = 'برداشته: ' . implode('، ', array_map([Permissions::class, 'label'], $change['removed']));
            }
            $lines[] = "{$role}: " . implode(' — ', $parts);
        }

        return $lines;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('تغییر دسترسی نقش‌ها | فهارس')->greeting('تغییر دسترسی نقش‌ها');
        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action('ماتریس دسترسی', route('admin.roles.index'))->salutation('فهارس، فهرستگان نسخ خطی ایران');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => 'تغییر دسترسی نقش‌ها',
            'body' => implode("\n", $this->lines()),
            'url' => route('admin.roles.index'),
        ];
    }
}
