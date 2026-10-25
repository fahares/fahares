<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Points the notification bot's webhook at this site, for linking users' own chats (backlog item 25). Run once per deploy URL. */
class SetTelegramWebhookCommand extends Command
{
    protected $signature = 'notifications:telegram-webhook {--delete : Remove the webhook instead}';

    protected $description = 'Sets (or removes) the Telegram webhook of the notification bot';

    public function handle(): int
    {
        $token = config('notifications.telegram.bot_token');
        $secret = config('notifications.telegram.webhook_secret');
        if (! $token || (! $secret && ! $this->option('delete'))) {
            $this->error('TELEGRAM_NOTIFY_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET must be set.');

            return self::FAILURE;
        }
        $response = $this->option('delete')
            ? Http::post("https://api.telegram.org/bot{$token}/deleteWebhook")
            : Http::post("https://api.telegram.org/bot{$token}/setWebhook", ['url' => route('telegram.webhook'), 'secret_token' => $secret, 'allowed_updates' => ['message']]);
        $this->line($response->body());

        return $response->json('ok') ? self::SUCCESS : self::FAILURE;
    }
}
