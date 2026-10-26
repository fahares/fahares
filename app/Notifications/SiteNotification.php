<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Shared shape of the notifications that are not about one suggestion (backlog item 25): a title, a few lines
 * and a link, for mail, the in-site list and Telegram (a user's own chat, or a site group routed by an
 * anonymous notifiable). A user gets the channels chosen for $this->type().
 */
abstract class SiteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = '';

    public function __construct()
    {
        $this->locale('fa');
    }

    public function type(): string
    {
        return static::TYPE;
    }

    abstract protected function title(): string;

    /** @return string[] */
    abstract protected function lines(object $notifiable): array;

    abstract protected function url(object $notifiable): string;

    protected function actionLabel(): string
    {
        return 'مشاهده';
    }

    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return array_map(fn ($c) => $c === 'telegram' ? TelegramChannel::class : $c, array_keys($notifiable->routes));
        }

        return $notifiable instanceof User ? NotificationPreferences::channelsFor($notifiable, $this->type()) : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title() . ' | فهارس')->greeting($this->title());
        foreach ($this->lines($notifiable) as $line) {
            $mail->line($line);
        }
        $mail->action($this->actionLabel(), $this->url($notifiable))->salutation('فهارس، فهرستگان نسخ خطی ایران');
        if ($notifiable instanceof User && ! NotificationPreferences::locked($this->type())) {
            $unsubscribe = URL::signedRoute('notifications.unsubscribe', ['user' => $notifiable->id, 'type' => $this->type(), 'channel' => 'mail']);
            $mail->line("برای قطع این نوع ایمیل: [لغو اشتراک]({$unsubscribe}) — همه‌ی تنظیمات اعلان: " . route('notifications.settings'));
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'title' => $this->title(),
            'body' => implode("\n", $this->lines($notifiable)),
            'url' => $this->url($notifiable),
        ];
    }

    /**
     * Browser push passes through the browser vendor's service, so it carries only the type's label and the link:
     * no names, emails or addresses (the details are one tap away, on the site).
     */
    public function toWebPush(object $notifiable, $notification = null): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(NotificationPreferences::types()[$this->type()]['label'] ?? 'فهارس')
            ->body('برای دیدن جزئیات بزنید.')
            ->icon(asset('images/brand/shamseh-192.png'))
            ->data(['url' => $this->url($notifiable)])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }

    public function toTelegram(object $notifiable): string
    {
        $lines = array_map(fn ($l) => e($l), $this->lines($notifiable));

        return '<b>' . e($this->title()) . "</b>\n\n" . implode("\n", $lines) . "\n\n" . '<a href="' . e($this->url($notifiable)) . '">' . e($this->actionLabel()) . '</a>';
    }
}
