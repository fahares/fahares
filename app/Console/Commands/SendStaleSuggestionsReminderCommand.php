<?php

namespace App\Console\Commands;

use App\Models\FieldSuggestion;
use App\Notifications\Notice;
use App\Notifications\NotificationPreferences;
use App\Review\CollectiveReview;
use Illuminate\Console\Command;

/** The weekly reminder of suggestions waiting longer than notifications.stale_after_days (backlog item 25). */
class SendStaleSuggestionsReminderCommand extends Command
{
    protected $signature = 'notifications:stale-suggestions';

    protected $description = 'Reminds the reviewers of suggestions left unreviewed for over a week';

    public function handle(): int
    {
        $days = (int) config('notifications.stale_after_days', 7);
        $stale = FieldSuggestion::where('status', 'pending')->where('created_at', '<', now()->subDays($days));
        $count = (clone $stale)->whereNull('review_queue')->count();
        $opinions = (clone $stale)->whereNotNull('review_queue')->distinct()->count('review_item_id');
        if ($count === 0 && $opinions === 0) {
            $this->info('Nothing stale.');

            return self::SUCCESS;
        }
        $oldest = (clone $stale)->min('created_at');

        $lines = array_filter([
            $count ? "{$count} پیشنهاد بیش از {$days} روز است که در انتظار داوری است." : null,
            $opinions ? "{$opinions} مورد «" . CollectiveReview::title() . "» با نظر پژوهشگران هم بیش از {$days} روز مانده است." : null,
            'کهن‌ترین: ' . \App\Support\Jalali::format(\Illuminate\Support\Carbon::parse($oldest)),
        ]);
        NotificationPreferences::broadcast('review.stale', new Notice('review.stale', 'پیشنهادهای مانده در انتظار داوری', array_values($lines), route('admin.suggestions.index'), 'میز داوری'));
        $this->info("Reminded: {$count} suggestions, {$opinions} collective items.");

        return self::SUCCESS;
    }
}
