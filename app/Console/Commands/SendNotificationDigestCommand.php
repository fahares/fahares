<?php

namespace App\Console\Commands;

use App\Models\FieldSuggestion;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Notifications\NotificationPreferences;
use App\Review\CollectiveReview;
use Illuminate\Console\Command;

/**
 * The daily digest (backlog item 25): for each user, the last day's new suggestions, «تصحیح جمعی» opinions,
 * sign-ups and the site notices of the other gathered types (technical alerts, access changes), for the types whose
 * «خلاصه‌ی روزانه» column the user has on. Opinions are announced only here, so a
 * busy day of collective review does not flood the reviewers (backlog item 80).
 */
class SendNotificationDigestCommand extends Command
{
    protected $signature = 'notifications:digest {--hours=24 : The period gathered}';

    protected $description = 'Emails each user the daily digest of the notification types they gather';

    public function handle(): int
    {
        $since = now()->subHours((int) $this->option('hours'));
        $suggestions = FieldSuggestion::where('status', 'pending')->whereNull('review_queue')->where('created_at', '>=', $since)->get();
        $opinions = FieldSuggestion::where('status', 'pending')->whereNotNull('review_queue')->where('updated_at', '>=', $since)
            ->selectRaw('review_queue, COUNT(*) AS opinions, COUNT(DISTINCT review_item_id) AS items')->groupBy('review_queue')->get();
        $users = User::where('created_at', '>=', $since)->orderBy('id')->get();

        $sent = 0;
        foreach (User::whereNotNull('email')->get() as $recipient) {
            $sections = [];
            if (NotificationPreferences::enabled($recipient, 'suggestion.submitted', 'digest')) {
                $mine = $suggestions->where('user_id', '!=', $recipient->id);
                if ($mine->isNotEmpty()) {
                    $sections['پیشنهادهای تازه'] = [$mine->count() . ' پیشنهاد در انتظار داوری'];
                }
            }
            if (NotificationPreferences::enabled($recipient, 'collective.opinions', 'digest') && $opinions->isNotEmpty()) {
                $sections['نظرهای تصحیح جمعی'] = $opinions->map(fn ($row) => (CollectiveReview::queue($row->review_queue)?->title() ?? $row->review_queue) . ": {$row->opinions} نظر درباره‌ی {$row->items} مورد")->all();
            }
            if (NotificationPreferences::enabled($recipient, 'user.registered', 'digest') && $users->isNotEmpty()) {
                $sections['کاربران تازه'] = $users->map(fn (User $u) => "{$u->name} ({$u->email})")->all();
            }
            foreach (self::GATHERED as $type => $heading) {
                if (NotificationPreferences::enabled($recipient, $type, 'digest') && ($lines = $this->noticeLines($recipient, $type, $since))) {
                    $sections[$heading] = $lines;
                }
            }
            if ($sections) {
                $recipient->notify(new DailyDigest($sections));
                $sent++;
            }
        }
        $this->info("Digests sent: {$sent}");

        return self::SUCCESS;
    }

    /** Types gathered from the recipient's own site notices, with their section heading */
    private const GATHERED = [
        'system.queue_failed' => 'شکست کارهای صف',
        'system.search_failed' => 'نمایه‌ی جست‌وجو',
        'system.command_finished' => 'دستورهای سرور',
        'permissions.changed' => 'تغییر دسترسی نقش‌ها',
    ];

    /** @return string[] one line per distinct title, with its count */
    private function noticeLines(User $recipient, string $type, \Carbon\CarbonInterface $since): array
    {
        return $recipient->notifications()->where('created_at', '>=', $since)->get()
            ->filter(fn ($n) => ($n->data['type'] ?? null) === $type)
            ->countBy(fn ($n) => $n->data['title'] ?? $type)
            ->map(fn (int $count, string $title) => $count > 1 ? "{$title} ({$count} بار)" : $title)
            ->values()->all();
    }
}
