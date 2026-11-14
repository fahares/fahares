<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Support\AnnouncementTelegram;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A public notice of the site in a member's own notifications (type site.announcement), so that one who was
 * away still finds it, and one who chose mail, Telegram or the browser gets it without coming to the site.
 * Only the in-site list is on by default.
 */
class AnnouncementNotice extends SiteNotification
{
    public const TYPE = 'site.announcement';

    public int $announcementId;

    public string $heading;

    public ?string $text;

    public string $link;

    public string $action;

    public function __construct(Announcement $announcement)
    {
        parent::__construct();
        $this->announcementId = $announcement->id;
        $this->heading = $announcement->title;
        $this->text = $announcement->body;
        $this->link = $announcement->link_url ? AnnouncementTelegram::absolute($announcement->link_url) : route('home');
        $this->action = $announcement->link_url ? ($announcement->link_label ?: 'بیشتر') : 'رفتن به فهارس';
    }

    protected function title(): string
    {
        return $this->heading;
    }

    protected function lines(object $notifiable): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $this->text)), fn ($line) => $line !== ''));
    }

    protected function url(object $notifiable): string
    {
        return $this->link;
    }

    protected function actionLabel(): string
    {
        return $this->action;
    }

    public function toArray(object $notifiable): array
    {
        // The id lets the site's popup and this entry count as one: reading either settles the other
        return parent::toArray($notifiable) + ['announcement_id' => $this->announcementId];
    }

    /** A public notice holds nothing private, so the push shows its own title and text. */
    public function toWebPush(object $notifiable, $notification = null): WebPushMessage
    {
        return parent::toWebPush($notifiable, $notification)
            ->title($this->heading)
            ->body(mb_strimwidth(implode(' ', $this->lines($notifiable)), 0, 160, '…') ?: 'تازه‌های فهارس');
    }
}
