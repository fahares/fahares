<?php

namespace App\Console\Commands;

use App\Messaging\Conversations;
use App\Models\MessageTemplate;
use App\Models\MessageThread;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Asks users whose name is in Latin letters only, and who have not chosen to keep it, to write it in Persian
 * (backlog item 75): a direct message from the template «persian_name», which the admin edits at
 * admin/messages/templates and the user can answer (backlog item 91). A user who already received it, or the
 * one-off notice that came before it, is skipped, so running the command again reaches only new users.
 */
class AskPersianNamesCommand extends Command
{
    protected $signature = 'fahares:ask-persian-names
        {--dry-run : List who would receive the notice, without sending}';

    protected $description = 'Ask users with a Latin-only name to write it in Persian (a direct message, in-site and by email)';

    private const TEMPLATE = 'persian_name';

    // The notification class that sent the request before item 91
    private const EARLIER_NOTICE = 'App\\Notifications\\PersianNameRequest';

    public function handle(): int
    {
        $users = User::whereNull('name_kept_at')->orderBy('id')->get()
            ->filter(fn (User $u) => $u->shouldWriteNameInPersian())
            ->reject(fn (User $u) => $u->notifications()->where('type', self::EARLIER_NOTICE)->exists()
                || MessageThread::where('user_id', $u->id)->where('template', self::TEMPLATE)->exists());

        $this->table(['id', 'name', 'role', 'sign-in'], $users->map(fn (User $u) => [$u->id, $u->name, $u->role, $u->google_id ? 'google' : 'form'])->all());
        $this->info(($this->option('dry-run') ? 'Would notify ' : 'Notifying ') . $users->count() . ' user(s).');
        if ($this->option('dry-run') || $users->isEmpty()) {
            return self::SUCCESS;
        }

        $template = MessageTemplate::where('key', self::TEMPLATE)->firstOrFail();
        foreach ($users as $user) {
            Conversations::sendDirect($user, null, MessageTemplate::render($template->subject, $user), MessageTemplate::render($template->body, $user), self::TEMPLATE);
        }
        $this->info('Sent.');

        return self::SUCCESS;
    }
}
