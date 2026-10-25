<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Notifications\Notice;
use App\Notifications\NotificationPreferences;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * When someone edits, merges, splits or removes a record that has suggestions waiting for review, the other
 * reviewers hear of it, so no one reviews against a record that has just changed (backlog item 25). Checked
 * after the commit, when the suggestion the action applied (if any) is no longer pending; once an hour per record.
 */
class AuditLogConflicts
{
    private const ACTIONS = ['update', 'merge', 'split', 'remove', 'delete'];

    public function created(AuditLog $log): void
    {
        if (! in_array($log->action, self::ACTIONS, true) || $log->auditable_type === FieldSuggestion::class) {
            return;
        }
        DB::afterCommit(fn () => $this->check($log));
    }

    private function check(AuditLog $log): void
    {
        try {
            $ids = array_filter([$log->auditable_id, $log->old_values['source_id'] ?? null]);
            $pending = FieldSuggestion::where('status', 'pending')->where('suggestable_type', $log->auditable_type)
                ->whereIn('suggestable_id', $ids)->whereKeyNot($log->new_values['suggestion_id'] ?? 0)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $log->user_id ?? 0))->count();
            if ($pending === 0 || ! Cache::add("review-conflict:{$log->auditable_type}:{$log->auditable_id}", true, now()->addHour())) {
                return;
            }
            $record = $log->auditable_type::find($log->auditable_id);
            $described = $record ? SuggestionRegistry::describe($record) : null;
            $action = AuditLog::ACTION_LABELS[$log->action] ?? $log->action;
            NotificationPreferences::broadcast('review.conflict', new Notice(
                'review.conflict',
                'تغییر رکوردی که پیشنهاد در انتظار دارد',
                [
                    ($log->user?->name ?? 'خط فرمان سرور') . " این رکورد را {$action} کرد: " . ($described['label'] ?? AuditLog::TYPE_LABELS[$log->auditable_type] ?? 'رکورد') . " #{$log->auditable_id}",
                    "{$pending} پیشنهاد درباره‌ی آن در انتظار داوری است؛ پیش از داوری، رکورد را دوباره ببینید.",
                ],
                route('admin.suggestions.index'),
                'میز داوری',
            ), $log->user_id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Review conflict notice failed: ' . $e->getMessage());
        }
    }
}
