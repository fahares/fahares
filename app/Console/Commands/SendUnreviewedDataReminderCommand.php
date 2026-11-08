<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Notifications\Notice;
use App\Notifications\NotificationPreferences;
use App\Support\Fa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The weekly count of machine matches nobody has reviewed yet (backlog item 82, step 14.102): copies matched to a
 * printed catalogue (item 51, «ارجاع‌های فهرست») and works tied to a printed entry of a source («پیوند منابع»),
 * each with what the week added and the sources that hold the most.
 */
class SendUnreviewedDataReminderCommand extends Command
{
    protected $signature = 'notifications:unreviewed-data {--days=7 : The period counted as new}';

    protected $description = 'Tells the reviewers of catalogue references how many machine matches still wait for review';

    public function handle(): int
    {
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $queues = [
            'تطبیق نسخه‌ها با فهرست‌های چاپی' => [SourceEntry::awaitingReview()->where('citable_type', Manuscript::class), route('admin.catalog-references.index')],
            'پیوند آثار به مدخل منابع' => [SourceEntry::awaitingReview()->where('citable_type', Work::class)->whereNotNull('source_item_id'), route('admin.source-links.index')],
        ];

        $lines = [];
        $first = null;
        foreach ($queues as $label => [$query, $url]) {
            $total = (clone $query)->count();
            if ($total === 0) {
                continue;
            }
            $first ??= $url;
            $new = (clone $query)->where('source_entries.created_at', '>=', $since)->count();
            $top = (clone $query)->join('sources', 'sources.id', '=', 'source_entries.source_id')
                ->select(DB::raw('COALESCE(sources.short_name, sources.title) as name'), DB::raw('count(*) as c'))
                ->groupBy('sources.id', 'sources.short_name', 'sources.title')->orderByDesc('c')->limit(3)->get()
                ->map(fn ($r) => "{$r->name} " . Fa::num($r->c))->implode('، ');
            $lines[] = "{$label}: " . Fa::num($total) . ' در انتظار بازبینی' . ($new ? '، ' . Fa::num($new) . ' تای آن در این هفته' : '') . ($top ? " (بیشترین: {$top})" : '') . '.';
            $lines[] = $url;
        }
        if (! $lines) {
            $this->info('Nothing unreviewed.');

            return self::SUCCESS;
        }

        NotificationPreferences::broadcast('review.unreviewed_data', new Notice(
            'review.unreviewed_data', 'داده‌های خودکار در انتظار بازبینی', $lines, $first, 'بازبینی',
        ));
        $this->info('Reminded: ' . count($lines) / 2 . ' queues.');

        return self::SUCCESS;
    }
}
