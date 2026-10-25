<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A user's own Telegram chat as a notification channel (backlog item 25). The settings page sends the user to
 * t.me/<bot>?start=<token>; Telegram then posts "/start <token>" to the webhook, which links that private chat.
 */
class TelegramLinkController extends Controller
{
    public function link(Request $request)
    {
        $bot = config('notifications.telegram.bot_username');
        abort_unless($bot && config('notifications.telegram.webhook_secret'), 404);
        $token = Str::random(32);
        Cache::put("telegram-link:{$token}", $request->user()->id, now()->addMinutes(30));

        return redirect()->away('https://t.me/' . ltrim($bot, '@') . '?start=' . $token);
    }

    public function unlink(Request $request)
    {
        $user = $request->user();
        if ($user->telegram_chat_id) {
            $this->say($user->telegram_chat_id, 'اتصال این گفت‌وگو به حساب فهارس برداشته شد.');
        }
        $user->forceFill(['telegram_chat_id' => null])->save();

        return back()->with('success', 'اتصال تلگرام برداشته شد.');
    }

    public function webhook(Request $request)
    {
        $secret = config('notifications.telegram.webhook_secret');
        abort_unless($secret && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $message = $request->input('message', []);
        $chat = $message['chat'] ?? [];
        $text = trim((string) ($message['text'] ?? ''));
        // Only private chats link; a group would show one person's notices to everyone in it
        if (($chat['type'] ?? '') !== 'private' || ! isset($chat['id'])) {
            return response()->json(['ok' => true]);
        }
        $chatId = (string) $chat['id'];

        if (preg_match('~^/start\s+(\S+)~', $text, $m)) {
            $userId = Cache::pull("telegram-link:{$m[1]}");
            $user = $userId ? User::find($userId) : null;
            if ($user) {
                User::where('telegram_chat_id', $chatId)->update(['telegram_chat_id' => null]);
                $user->forceFill(['telegram_chat_id' => $chatId])->save();
                $this->say($chatId, "این گفت‌وگو به حساب «{$user->name}» در فهارس وصل شد. نوع اعلان‌ها را در ستون «تلگرام» تنظیمات اعلان برگزینید: " . route('notifications.settings'));
            } else {
                $this->say($chatId, 'این پیوند منقضی شده است. از صفحه‌ی تنظیمات اعلان در فهارس دوباره «اتصال تلگرام» را بزنید.');
            }
        } elseif ($text === '/stop') {
            User::where('telegram_chat_id', $chatId)->update(['telegram_chat_id' => null]);
            $this->say($chatId, 'اتصال این گفت‌وگو به فهارس برداشته شد.');
        }

        return response()->json(['ok' => true]);
    }

    private function say(string $chatId, string $text): void
    {
        $token = config('notifications.telegram.bot_token');
        if (! $token) {
            return;
        }
        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]);
        } catch (\Throwable $e) {
            Log::warning('Telegram reply failed: ' . $e->getMessage());
        }
    }
}
