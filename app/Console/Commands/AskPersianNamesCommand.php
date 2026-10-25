<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\PersianNameRequest;
use Illuminate\Console\Command;

/**
 * Asks users whose name is in Latin letters only, and who have not chosen to keep it, to write it in Persian
 * (backlog item 75): one notice inside the site and by email. A user who already received it is skipped,
 * so running the command again reaches only new users.
 */
class AskPersianNamesCommand extends Command
{
    protected $signature = 'fahares:ask-persian-names
        {--dry-run : List who would receive the notice, without sending}';

    protected $description = 'Ask users with a Latin-only name to write it in Persian (in-site notice and email)';

    public function handle(): int
    {
        $users = User::whereNull('name_kept_at')->orderBy('id')->get()
            ->filter(fn (User $u) => $u->shouldWriteNameInPersian())
            ->reject(fn (User $u) => $u->notifications()->where('type', PersianNameRequest::class)->exists());

        $this->table(['id', 'name', 'role', 'sign-in'], $users->map(fn (User $u) => [$u->id, $u->name, $u->role, $u->google_id ? 'google' : 'form'])->all());
        $this->info(($this->option('dry-run') ? 'Would notify ' : 'Notifying ') . $users->count() . ' user(s).');
        if ($this->option('dry-run') || $users->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $user->notify(new PersianNameRequest);
        }
        $this->info('Sent.');

        return self::SUCCESS;
    }
}
