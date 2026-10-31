<?php

namespace App\Notifications;

use App\Models\FieldSuggestion;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Shared shape of the suggestion notifications: one title, a few lines and a link, rendered
 * for mail, the in-site list and Telegram. Users get the channels they chose for the type;
 * a route-only notifiable (a guest's email, the reviewers' group) gets the channel it routes.
 */
abstract class SuggestionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = '';

    public function __construct(public FieldSuggestion $suggestion)
    {
        // The site's messages are Persian whatever APP_LOCALE says, so the mail template's own strings follow
        $this->locale('fa');
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

        return $notifiable instanceof User ? NotificationPreferences::channelsFor($notifiable, static::TYPE) : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title() . ' | فهارس')->greeting($this->title());
        foreach ($this->lines($notifiable) as $line) {
            $mail->line($line);
        }
        $mail->action($this->actionLabel(), $this->url($notifiable))->salutation(\App\Models\SiteSetting::get('mail.salutation'));

        if ($notifiable instanceof User) {
            $unsubscribe = URL::signedRoute('notifications.unsubscribe', ['user' => $notifiable->id, 'type' => static::TYPE, 'channel' => 'mail']);
            $mail->line("برای قطع این نوع ایمیل: [لغو اشتراک]({$unsubscribe}) — همه‌ی تنظیمات اعلان: " . route('notifications.settings'));
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => static::TYPE,
            'title' => $this->title(),
            'body' => implode("\n", $this->lines($notifiable)),
            'url' => $this->url($notifiable),
        ];
    }

    public function toTelegram(object $notifiable): string
    {
        $lines = array_map(fn ($l) => e($l), $this->lines($notifiable));

        return '<b>' . e($this->title()) . "</b>\n\n" . implode("\n", $lines) . "\n\n" . '<a href="' . e($this->url($notifiable)) . '">' . e($this->actionLabel()) . '</a>';
    }

    /** Browser push passes through the browser vendor's service, so it carries the title, the record and the link only. */
    public function toWebPush(object $notifiable, $notification = null): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->body(self::short($this->placeLine(), 120))
            ->icon(asset('images/brand/shamseh-192.png'))
            ->tag(static::TYPE . '-' . $this->suggestion->id)
            ->data(['url' => $this->url($notifiable)])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }

    /** The "where" line: the record, or for site feedback the page it was sent from. */
    protected function placeLine(): string
    {
        return $this->suggestion->kind === 'feedback' ? $this->suggestion->place_label : 'رکورد: ' . $this->recordLabel();
    }

    protected function recordLabel(): string
    {
        $record = $this->suggestion->suggestable;

        return $record ? SuggestionRegistry::describe($record)['label'] : 'رکورد حذف‌شده';
    }

    protected function recordUrl(): string
    {
        if ($this->suggestion->kind === 'feedback') {
            return $this->suggestion->place_url ?? url('/');
        }
        $record = $this->suggestion->suggestable;

        return $record ? SuggestionRegistry::describe($record)['url'] : url('/');
    }

    protected static function short(?string $text, int $limit = 300): string
    {
        return Str::limit(trim((string) $text), $limit) ?: '—';
    }
}
