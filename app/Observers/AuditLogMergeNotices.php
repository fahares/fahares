<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Notifications\Notice;
use App\Notifications\NotificationPreferences;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When a record is merged into another, each user whose suggestion on the removed record still waits for review
 * hears of it (backlog item 82, step 14.102). Every merger carries the open suggestions to the kept record
 * (`suggestion_ids` in the log; people since step 14.113); a person merge logged before that left them on the removed
 * record, which now redirects, and they are found by its id. Checked after
 * the commit, so the suggestion the merge applied is no longer pending; the one who merged is not told.
 */
class AuditLogMergeNotices
{
    public const TYPE = 'suggestion.record_merged';

    private const TYPES = [Manuscript::class, Work::class, Library::class, Person::class];

    public function created(AuditLog $log): void
    {
        if ($log->action !== 'merge' || ! in_array($log->auditable_type, self::TYPES, true) || empty($log->old_values['source_id'])) {
            return;
        }
        DB::afterCommit(fn () => $this->notify($log));
    }

    private function notify(AuditLog $log): void
    {
        try {
            $old = (array) $log->old_values;
            $moved = array_key_exists('suggestion_ids', $old);
            $suggestions = FieldSuggestion::where('status', 'pending')->whereNotNull('user_id')
                ->when($moved,
                    fn ($q) => $q->whereIn('id', (array) $old['suggestion_ids']),
                    fn ($q) => $q->where('suggestable_type', $log->auditable_type)->where('suggestable_id', $old['source_id']))
                ->whereKeyNot($log->new_values['suggestion_id'] ?? 0)
                ->when($log->user_id, fn ($q, $actor) => $q->where('user_id', '!=', $actor))
                ->with('user')->get();
            if ($suggestions->isEmpty()) {
                return;
            }

            $kind = AuditLog::TYPE_LABELS[$log->auditable_type] ?? 'رکورد';
            $kept = $log->auditable_type::find($log->auditable_id);
            $described = $kept ? SuggestionRegistry::describe($kept) : null;
            $removed = $this->removedLabel($old);
            $keptLabel = $described['label'] ?? "#{$log->auditable_id}";

            foreach ($suggestions->groupBy('user_id') as $own) {
                $user = $own->first()->user;
                if (! $user) {
                    continue;
                }
                $count = \App\Support\Fa::num($own->count());
                $user->notify(new Notice(
                    self::TYPE,
                    'ادغام رکوردی که پیشنهاد شما درباره‌ی آن است',
                    array_values(array_filter([
                        "{$kind} «{$removed}» در «{$keptLabel}» ادغام شد.",
                        $moved
                            ? "{$count} پیشنهاد شما درباره‌ی آن، که هنوز در انتظار داوری است، اکنون به رکورد ماندگار پیوسته است."
                            : "{$count} پیشنهاد شما درباره‌ی آن هنوز در انتظار داوری است.",
                        'شاید پیشنهادتان با داده‌ی رکورد ماندگار دیگر لازم نباشد، یا بخواهید آن را بازنگری کنید.',
                    ])),
                    $described['url'] ?? route('my.suggestions'),
                    $described ? 'دیدن رکورد ماندگار' : 'پیشنهادهای من',
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Merge notice failed: ' . $e->getMessage());
        }
    }

    /** The removed record's name, from the log's snapshot */
    private function removedLabel(array $old): string
    {
        // RecordMerger keeps the attributes under 'attributes'; the library and person mergers keep them flat
        $snapshot = (array) ($old['source_snapshot'] ?? []);
        $snapshot = (array) ($snapshot['attributes'] ?? $snapshot);
        $label = $old['source_name'] ?? $snapshot['primary_title'] ?? $snapshot['name'] ?? null;
        if (! $label && isset($snapshot['shelfmark'])) {
            $label = trim(($snapshot['library'] ?? '') . ' ' . $snapshot['shelfmark']);
        }

        return $label ?: '#' . $old['source_id'];
    }
}
