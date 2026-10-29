<?php

namespace App\Suggestions;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The public record of approved corrections (backlog items 39 and 63): which suggestions are shown,
 * how each reads as one line of change, and whose name stands beside it. Used by the contributions
 * page and meant for the correction history of each record page.
 *
 * Never shown: pending, rejected, withdrawn or converted suggestions, site feedback, a general report
 * without a public summary, and a reviewer's own typo fixes (direct-edit makes no suggestion row).
 * A name and an avatar appear only where the submitter allowed the name (credit_name).
 */
class Contributions
{
    public const STAFF_LABEL = 'ویراستار فهارس';

    public const ANONYMOUS_LABEL = 'یک پژوهشگر';

    /** Kinds of correction for the filter: kind => label. */
    public const KINDS = [
        'field' => 'اصلاح فیلد',
        'note' => 'یادداشت پژوهشی',
        'relation' => 'رابطه و اصلاح ساختاری',
    ];

    public static function publicQuery(): Builder
    {
        return FieldSuggestion::query()
            ->where('status', 'approved')
            ->whereNotNull('suggestable_type')
            ->where(fn ($q) => $q->whereIn('kind', array_keys(self::KINDS))
                ->orWhere(fn ($g) => $g->where('kind', 'general')->whereNotNull('public_note')->where('public_note', '!=', '')));
    }

    /** Suggestions credited to one user under their name; anonymous ones stay unlinked from them. */
    public static function creditedTo(User $user): Builder
    {
        return self::publicQuery()->where('user_id', $user->id)->whereNotNull('credit_name')->where('credit_name', '!=', '');
    }

    /**
     * Who is shown beside a correction.
     *
     * @return array{kind: string, name: string, user: ?User, url: ?string}
     */
    public static function contributor(FieldSuggestion $s): array
    {
        $name = trim((string) $s->credit_name);
        if ($name !== '' && $s->user) {
            return ['kind' => 'credited', 'name' => $name, 'user' => $s->user, 'url' => route('contributors.show', $s->user)];
        }
        if ($name !== '') {
            // A guest's suggestion credited by a reviewer: the name, but no page or picture
            return ['kind' => 'credited', 'name' => $name, 'user' => null, 'url' => null];
        }
        if ($s->user?->canAccessAdmin()) {
            return ['kind' => 'staff', 'name' => self::STAFF_LABEL, 'user' => null, 'url' => null];
        }

        return ['kind' => 'anonymous', 'name' => self::ANONYMOUS_LABEL, 'user' => null, 'url' => null];
    }

    /**
     * The corrections as the public reads them, one summary per suggestion, with the audit log read
     * once for the structural ones whose earlier state the resolution does not keep.
     *
     * @param  iterable<FieldSuggestion>  $suggestions
     * @return Collection<int, array>
     */
    public static function summarize(iterable $suggestions): Collection
    {
        $suggestions = collect($suggestions);
        $logs = self::auditLogs($suggestions->where('kind', 'relation')->pluck('id')->all());

        return self::joinConverted($suggestions->map(fn (FieldSuggestion $s) => self::summary($s, $logs[$s->id] ?? null)));
    }

    /**
     * Field edits converted from one suggestion (death year unknown, century 4) read as one correction,
     * in the place of the first of them; the original itself may be approved too, so it groups by its own id.
     */
    private static function joinConverted(Collection $summaries): Collection
    {
        $key = fn (array $item) => $item['suggestion']->kind === 'field' && ! $item['suggestion']->review_queue
            ? $item['suggestion']->suggestable_type . ':' . $item['suggestion']->suggestable_id . ':' . ($item['suggestion']->payload['derived_from'] ?? $item['suggestion']->id)
            : 'item:' . $item['suggestion']->id;
        $lines = fn ($group, string $side) => $group->every(fn ($i) => $i[$side] === null) ? null
            : ['label' => $group->map(fn ($i) => $i['action'] . ': ' . ($i[$side]['label'] ?? 'نامشخص'))->implode('؛ '), 'url' => null];

        return $summaries->groupBy($key)->map(fn (Collection $group) => $group->count() === 1 ? $group->first() : array_merge($group->first(), [
            'action' => $group->pluck('action')->implode(' و '),
            'before' => $lines($group, 'before'),
            'after' => $lines($group, 'after'),
        ]))->values();
    }

    /**
     * @return array{suggestion: FieldSuggestion, date: mixed, action: string, record: ?array, type: string,
     *     before: ?array, after: ?array, text: ?string, standing: ?string, contributor: array}
     */
    public static function summary(FieldSuggestion $s, ?AuditLog $log = null): array
    {
        $summary = [
            'suggestion' => $s,
            'date' => $s->reviewed_at ?? $s->updated_at,
            'action' => $s->field_label,
            'record' => $s->suggestable ? self::link($s->suggestable) : null,
            'type' => SuggestionRegistry::typeLabel($s->suggestable_type),
            'before' => null,
            'after' => null,
            'text' => null,
            'standing' => $s->kind === 'field' ? $s->standing_label : null,
            'contributor' => self::contributor($s),
        ];

        // An answer of «تصحیح جمعی» that a reviewer agreed with reads as the answer, not as a field's before and after
        if ($s->review_queue && ($queue = \App\Review\CollectiveReview::queue($s->review_queue))) {
            return array_merge($summary, [
                'action' => config('contributions.review_title') . ': ' . $queue->title(),
                'standing' => null,
                'text' => $queue->describe($s),
            ]);
        }

        return match ($s->kind) {
            'field' => array_merge($summary, [
                'before' => self::plain($s->current_value),
                'after' => self::plain($s->display_value),
            ]),
            'note' => array_merge($summary, [
                'text' => self::short(is_array($s->payload) ? NoteTypes::summary((string) $s->field_name, $s->payload) ?: $s->suggested_value : $s->suggested_value),
            ]),
            'general' => array_merge($summary, ['text' => self::short($s->public_note)]),
            'relation' => array_merge($summary, self::relationSummary($s, $log)),
            default => $summary,
        };
    }

    /** The parts of a structural correction's summary: what it was, what it became, and the record it now lives on. */
    private static function relationSummary(FieldSuggestion $s, ?AuditLog $log): array
    {
        $relation = $s->relationDefinition();
        $resolution = $s->resolution ?? [];
        $old = $log?->old_values ?? [];
        $target = $relation['target'] ?? null;

        return match ($relation['apply'] ?? null) {
            // Earlier work: kept in the resolution since backlog item 63, before that only in the audit log
            'move' => [
                'before' => self::recordLink('work', $resolution['from_work_id'] ?? $old['work_id'] ?? null),
                'after' => self::recordLink('work', $resolution['work_id'] ?? null),
            ],
            'merge', 'merge_library' => [
                'record' => self::recordLink(SuggestionRegistry::typeKey($s->suggestable_type), $resolution['kept_id'] ?? null) ?? self::link($s->suggestable),
                'text' => 'رکورد تکراری «' . self::snapshotLabel($old['source_snapshot'] ?? null, $resolution['removed_id'] ?? null) . '» در این رکورد ادغام شد.',
            ],
            'contributor_set' => [
                'before' => self::recordLink('person', $resolution['previous_id'] ?? null),
                'after' => self::recordLink('person', $resolution['person_id'] ?? null),
            ],
            'contributor_add' => ['after' => self::recordLink('person', $resolution['person_id'] ?? null)],
            'alias' => ['after' => self::plain($resolution['alias'] ?? $s->suggested_value)],
            'person_role' => [
                (! empty($resolution['removed']) ? 'before' : 'after') => self::recordLink('person', $resolution['person_id'] ?? null) ?? self::plain($resolution['name'] ?? $s->payload['name'] ?? null),
                'text' => ($role = $resolution['role'] ?? $s->payload['role'] ?? null)
                    ? 'نقش: ' . (SuggestionRegistry::personRoles($s->suggestable_type)[$role] ?? $role) . (! empty($resolution['removed']) ? ' (حذف شد)' : '')
                    : null,
            ],
            'record_relation' => self::recordRelation($s, $resolution, $target),
            'relocate' => [
                'before' => self::plain(self::location($old['library'] ?? null, $old['shelfmark'] ?? null)),
                'after' => self::plain(self::location($log?->new_values['library'] ?? null, $log?->new_values['shelfmark'] ?? null))
                    ?? self::recordLink('library', $resolution['library_id'] ?? null),
            ],
            'rename_library' => [
                'before' => self::plain($old['name'] ?? null),
                'after' => self::plain($resolution['name'] ?? $s->suggested_value),
            ],
            'remove' => [
                'record' => null,
                'text' => 'رکورد زائد «' . self::snapshotLabel($old['snapshot']['attributes'] ?? null, $resolution['removed_id'] ?? $s->suggestable_id) . '» حذف شد.',
            ],
            'split' => [
                'after' => self::recordLink(SuggestionRegistry::typeKey($s->suggestable_type), $resolution['created_id'] ?? null),
                'text' => 'رکورد تازه‌ای برای ' . SuggestionRegistry::typeLabel($s->suggestable_type) . ' دوم ساخته شد.',
            ],
            default => [],
        };
    }

    /** @return array<int, AuditLog> by suggestion id */
    private static function auditLogs(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $ids = array_map('intval', $ids);

        return AuditLog::whereRaw("JSON_VALUE(new_values, '$.suggestion_id') IN (" . implode(',', $ids) . ')')
            ->orderBy('id')
            ->get()
            ->keyBy(fn ($log) => (int) $log->new_values['suggestion_id'])
            ->all();
    }

    private static function link(?Model $record): ?array
    {
        if (! $record) {
            return null;
        }
        $described = SuggestionRegistry::describe($record);

        return ['label' => $described['label'], 'url' => $described['url']];
    }

    private static function recordLink(?string $type, $id): ?array
    {
        $class = $type ? SuggestionRegistry::modelClass($type) : null;
        if (! $class || ! $id) {
            return null;
        }
        $record = $class::find($id);

        return $record ? self::link($record) : ['label' => SuggestionRegistry::typeLabel($type) . ' #' . \App\Support\Fa::digits((string) $id) . ' (دیگر وجود ندارد)', 'url' => null];
    }

    private static function plain(?string $text): ?array
    {
        $text = self::short($text);

        return $text === null ? null : ['label' => $text, 'url' => null];
    }

    private static function short(?string $text, int $limit = 160): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        return $text === '' ? null : Str::limit($text, $limit);
    }

    private static function snapshotLabel(?array $snapshot, $id): string
    {
        return $snapshot['primary_title'] ?? $snapshot['name'] ?? ($id ? '#' . \App\Support\Fa::digits((string) $id) : 'نامشخص');
    }

    private static function location(?string $library, ?string $shelfmark): ?string
    {
        $text = trim(($library ?? '') . ($shelfmark ? '، ش ' . $shelfmark : ''), '، ');

        return $text === '' ? null : $text;
    }

    /** «این اثر شرحی است بر اثر انتخاب‌شده» read from the record's side: the resolution keeps from/to, not the option picked. */
    private static function recordRelation(FieldSuggestion $s, array $resolution, ?string $target): array
    {
        $forward = ($resolution['from'] ?? $s->suggestable_id) === $s->suggestable_id;
        $other = $forward ? ($resolution['to'] ?? null) : ($resolution['from'] ?? null);
        $type = $resolution['type'] ?? ltrim((string) ($s->payload['role'] ?? ''), '~');
        $option = $type === '' ? null : (RecordRelations::options($s->suggestable_type)[($forward || $type === 'same_set' ? '' : '~') . $type] ?? null);

        return [
            'after' => self::recordLink($target, $other ?? $s->payload['target_id'] ?? null),
            'text' => $option ? str_replace('انتخاب‌شده', 'زیر', $option) : null,
        ];
    }
}
