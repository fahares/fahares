<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Sends a notification's toTelegram() text (Telegram HTML) to the chat the notifiable routes to,
 * e.g. Notification::route('telegram', $chatId). Without a bot token nothing is sent.
 */
class TelegramChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $token = config('notifications.telegram.bot_token');
        $chatId = $notifiable->routeNotificationFor('telegram', $notification);
        if (! $token || ! $chatId || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $notification->toTelegram($notifiable),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ])->throw();
    }
}
