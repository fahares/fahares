<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** The test push of the notification settings page (backlog item 25); sent at once, not queued. */
class PushTest extends Notification
{
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, $notification = null): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('اعلان آزمایشی فهارس')
            ->body('اعلان مرورگر در این دستگاه کار می‌کند.')
            ->icon(asset('images/brand/shamseh-192.png'))
            ->data(['url' => route('notifications.settings')]);
    }
}
