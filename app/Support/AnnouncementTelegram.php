<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\SiteSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Posts a site notice to the site's Telegram channel with the notification bot, which must be an
 * admin of that channel. A notice is posted once, when it first runs; a later change of its text edits
 * the same post. Only a notice for everyone goes out, since the channel is public. The channel and
 * the footer closing every post are site settings (telegram.channel, telegram.footer).
 */
class AnnouncementTelegram
{
    /** Telegram's limits, counted in UTF-16 units of the text without its markup */
    public const TEXT_LIMIT = 4096;

    public const CAPTION_LIMIT = 1024;

    public const DEFAULT_LINK_LABEL = 'بیشتر بخوانید';

    /** The post as Telegram HTML: the notice (or its own Telegram text), its link, the footer. */
    public static function compose(?string $title, ?string $body, ?string $own, ?string $linkUrl, ?string $linkLabel): string
    {
        $e = fn (?string $s) => htmlspecialchars(trim((string) $s), ENT_QUOTES | ENT_HTML401, 'UTF-8');

        $parts = [];
        if (trim((string) $own) !== '') {
            $parts[] = $e($own);
        } else {
            $parts[] = '<b>' . $e($title) . '</b>';
            if (trim((string) $body) !== '') {
                $parts[] = $e($body);
            }
        }
        if ($linkUrl) {
            $parts[] = '🔗 <a href="' . $e(self::absolute($linkUrl)) . '">' . $e($linkLabel ?: self::DEFAULT_LINK_LABEL) . '</a>';
        }
        $footer = trim((string) SiteSetting::get('telegram.footer'));
        if ($footer !== '') {
            $parts[] = $e($footer);
        }

        return implode("\n\n", $parts);
    }

    public static function for(Announcement $a): string
    {
        return self::compose($a->title, $a->body, $a->telegram_text, $a->link_url, $a->link_label);
    }

    /** The length Telegram counts: UTF-16 units of the visible text. */
    public static function length(string $html): int
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML401, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? url($url) : $url;
    }

    /** The channel as the API wants it: @name, or a numeric chat id */
    public static function channel(): ?string
    {
        $name = trim((string) SiteSetting::get('telegram.channel'));
        if ($name === '') {
            return null;
        }

        return preg_match('/^-?\d+$/', $name) ? $name : '@' . ltrim($name, '@');
    }

    /**
     * Brings the channel's post in line with the notice: 'sent', 'edited', 'failed' (the reason is
     * kept in telegram_error and the notice itself stays saved) or 'none' when there is nothing to do.
     */
    public static function sync(Announcement $a): string
    {
        if (! $a->telegram || $a->audience !== 'all') {
            return 'none';
        }
        $posted = (bool) $a->telegram_message_id;
        if (! $posted && ! $a->isRunning()) {
            return 'none';
        }
        $text = self::for($a);
        if ($posted && $text === $a->telegram_posted_text) {
            return 'none';
        }

        try {
            $chat = self::channel();
            if (! config('notifications.telegram.bot_token') || ! $chat) {
                throw new RuntimeException('توکن ربات یا نام کانال تلگرام تنظیم نشده است.');
            }
            $link = $a->link_url ? self::absolute($a->link_url) : null;
            // The preview card is drawn from the notice's own link, never from an address in the footer
            $preview = ['link_preview_options' => $link ? ['url' => $link] : ['is_disabled' => true]];

            if ($posted) {
                $a->telegram_photo
                    ? self::call('editMessageCaption', ['chat_id' => $chat, 'message_id' => $a->telegram_message_id, 'caption' => $text, 'parse_mode' => 'HTML'])
                    : self::call('editMessageText', ['chat_id' => $chat, 'message_id' => $a->telegram_message_id, 'text' => $text, 'parse_mode' => 'HTML'] + $preview);
                $a->forceFill(['telegram_posted_text' => $text, 'telegram_error' => null])->saveQuietly();

                return 'edited';
            }

            $photo = $a->image_path && Storage::disk('public')->exists($a->image_path);
            $result = $photo
                ? self::call('sendPhoto', ['chat_id' => $chat, 'caption' => $text, 'parse_mode' => 'HTML'], $a->image_path)
                : self::call('sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML'] + $preview);
            $a->forceFill([
                'telegram_message_id' => $result['message_id'] ?? null,
                'telegram_photo' => $photo,
                'telegram_posted_text' => $text,
                'telegram_sent_at' => now(),
                'telegram_error' => null,
            ])->saveQuietly();

            return 'sent';
        } catch (\Throwable $e) {
            // A connection error's own message carries the request address, and with it the bot token
            $reason = $e instanceof ConnectionException ? 'تلگرام پاسخ نداد.' : $e->getMessage();
            $a->forceFill(['telegram_error' => mb_substr($reason, 0, 500)])->saveQuietly();

            return 'failed';
        }
    }

    /** The post's address in the channel, for a public channel */
    public static function postUrl(Announcement $a): ?string
    {
        $chat = self::channel();

        return $a->telegram_message_id && $chat && $chat[0] === '@'
            ? 'https://t.me/' . substr($chat, 1) . '/' . $a->telegram_message_id
            : null;
    }

    private static function call(string $method, array $params, ?string $photoPath = null): array
    {
        $token = config('notifications.telegram.bot_token');
        $request = Http::timeout(20);
        if ($photoPath) {
            $request = $request->attach('photo', Storage::disk('public')->get($photoPath), basename($photoPath));
        }
        $response = $request->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        if (! $response->json('ok')) {
            // Saving a notice whose post already reads the same is not a failure
            if (str_contains((string) $response->json('description'), 'message is not modified')) {
                return [];
            }
            throw new RuntimeException('تلگرام نپذیرفت: ' . ($response->json('description') ?: 'HTTP ' . $response->status()));
        }

        return (array) $response->json('result');
    }
}
