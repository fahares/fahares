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

    /** The researchers' corrections: approved and public, without the readings a merge kept on its own (step 14.74). */
    public static function publicQuery(): Builder
    {
        return self::recordQuery()->whereNot(fn ($q) => self::machineReadings($q));
    }

    /** What a record page lists: the public corrections and the readings a merge kept («قول دیگر» with no submitter). */
    private static function recordQuery(): Builder
    {
        return FieldSuggestion::query()
            ->where('status', 'approved')
            ->whereNotNull('suggestable_type')
            ->where(fn ($q) => $q->whereIn('kind', array_keys(self::KINDS))
                ->orWhere(fn ($g) => $g->where('kind', 'general')->whereNotNull('public_note')->where('public_note', '!=', '')));
    }

    /** COALESCE: under whereNot a NULL would drop every guest's correction without a resolution too. */
    private static function machineReadings($q)
    {
        return $q->whereNull('user_id')->whereIn(\Illuminate\Support\Facades\DB::raw("COALESCE(JSON_VALUE(resolution, '$.origin'), '')"), array_keys(FieldSuggestion::MACHINE_ORIGINS));
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
        if ($s->machineOrigin()) {
            // no one sent it: the other catalogue or record whose value a merge kept
            return ['kind' => 'machine', 'name' => $name !== '' ? $name : $s->machine_label, 'user' => null, 'url' => null];
        }
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
            'merge', 'merge_library', 'merge_people' => [
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
            // a source's volumes got a cataloger (step 14.58)
            'volume_cataloger' => [
                'after' => self::recordLink('cataloger', $resolution['cataloger_id'] ?? null) ?? self::plain($s->payload['name'] ?? null),
                'text' => ($role = $resolution['role'] ?? $s->payload['role'] ?? null) ? 'نقش در جلد: ' . (\App\Suggestions\VolumeCatalogers::ROLES[$role] ?? $role) : null,
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

    /**
     * The correction history of one record page (backlog item 39): its own approved corrections and those
     * made elsewhere that changed it, a copy moved to or from this work, a duplicate merged into it, a relation
     * from the other side, a person or library given a work or a copy. Newest first, one entry per correction.
     *
     * @return Collection<int, array> summaries with 'line', the sentence read from this record's side
     */
    public static function forRecord(Model $record, int $limit = 200): Collection
    {
        $type = $record::class;
        $id = (int) $record->getKey();
        $json = fn ($q, string $path) => $q->orWhereRaw("JSON_VALUE(resolution, '$.{$path}') = ?", [$id]);
        $names = fn (string $on, array $applies) => array_keys(array_filter(
            SuggestionRegistry::RELATIONS[$on] ?? [], fn ($r) => in_array($r['apply'] ?? null, $applies, true)));

        $query = self::recordQuery()->where(function ($q) use ($type, $id, $json, $names, $record) {
            $q->where(fn ($own) => $own->where('suggestable_type', $type)->where('suggestable_id', $id));
            $key = SuggestionRegistry::typeKey($type);
            // a duplicate merged into this record, a record split off from it, a relation from the other side
            $q->orWhere(fn ($same) => $same->where('suggestable_type', $type)->where('kind', 'relation')->where(function ($r) use ($json, $names, $key) {
                $json($json($r, 'kept_id'), 'created_id');
                $r->orWhere(fn ($rel) => $rel->whereIn('field_name', $names($key, ['record_relation']) ?: ['-'])
                    ->where(fn ($ends) => $json($json($ends, 'from'), 'to')));
            }));
            if ($record instanceof \App\Models\Work) {
                $q->orWhere(fn ($moved) => $moved->where('suggestable_type', \App\Models\Manuscript::class)
                    ->whereIn('field_name', $names('manuscript', ['move']))
                    ->where(fn ($ends) => $json($json($ends, 'work_id'), 'from_work_id')));
            }
            if ($record instanceof \App\Models\Library) {
                $q->orWhere(fn ($moved) => $moved->where('suggestable_type', \App\Models\Manuscript::class)
                    ->whereIn('field_name', $names('manuscript', ['relocate']))
                    ->where(fn ($ends) => $json($json($ends, 'library_id'), 'from_library_id')));
            }
            if ($record instanceof \App\Models\Person) {
                foreach (['work', 'manuscript'] as $on) {
                    $q->orWhere(fn ($linked) => $linked->where('suggestable_type', SuggestionRegistry::modelClass($on))
                        ->whereIn('field_name', $names($on, ['contributor_set', 'contributor_add', 'person_role']))
                        ->where(fn ($ends) => $json($json($ends, 'person_id'), 'previous_id')));
                }
            }
        });

        $suggestions = $query->with(['user', 'suggestable'])
            ->orderByDesc('reviewed_at')->orderByDesc('id')->limit($limit)->get();

        $items = self::summarize($suggestions)->map(fn (array $item) => $item + ['line' => self::line($item, $record), 'children' => []]);

        return $record instanceof \App\Models\Manuscript ? self::withCopyMerges($items, $record) : $items;
    }

    /**
     * A copy two catalogues describe was made one record by fahares:link-copy-references (step 14.16), with no
     * suggestion of its own: its former location (note «همان نسخه بالا») reads as one line, and the readings that merge
     * kept go under it instead of standing as lines of their own (step 14.78).
     */
    private static function withCopyMerges(Collection $items, \App\Models\Manuscript $copy): Collection
    {
        $merges = collect((array) ($copy->metadata['former_locations'] ?? []))
            ->filter(fn ($f) => is_array($f) && empty($f['suggestion_id']) && str_contains((string) ($f['note'] ?? ''), 'همان نسخه'))
            ->values();
        if ($merges->isEmpty()) {
            return $items;
        }
        $isReading = fn (array $item) => $item['suggestion']?->machineOrigin() === 'copy_merge';
        $readings = $items->filter($isReading);
        $events = $merges->map(function (array $f) use ($readings, $merges) {
            $catalog = trim((string) ($f['catalog'] ?? ''));
            // the readings cite «فهرست {catalog}»; with one merge on the copy they are all its own
            $own = $readings->filter(fn ($r) => $merges->count() === 1 || trim((string) $r['suggestion']->credit_name) === 'فهرست ' . $catalog);
            $place = trim(implode('، ', array_filter([$f['library'] ?? null, isset($f['shelfmark']) ? 'ش ' . $f['shelfmark'] : null])));

            return [
                'suggestion' => null,
                'key' => 'copy-merge-' . md5(json_encode($f)),
                'date' => $own->min('date'),
                'contributor' => ['kind' => 'machine', 'name' => FieldSuggestion::MACHINE_ORIGINS['copy_merge'], 'user' => null, 'url' => null],
                // with the leaves it had in the former volume, when it was one treatise of a collection (step 14.80)
                'line' => ['در یکی شدن دو معرفی این نسخه، معرفی «' . ($place ?: 'نامشخص') . '»'
                    . (($extent = \App\Support\FormerLocation::extent($f)) ? '، ' . $extent : '') . ($catalog !== '' ? ' (' . $catalog . ')' : '')
                    . ' در این رکورد ادغام شد. فنخا درباره‌ی آن نوشته است: «همان نسخه بالا».'],
                'children' => $own->map(fn ($r) => ['قول ' . $r['contributor']['name'] . ' برای «' . $r['action'] . '»: «', $r['after']['label'] ?? '', '»'])->values()->all(),
                'claimed' => $own->keys()->all(),
            ];
        });
        $claimed = $events->pluck('claimed')->flatten()->all();

        return $items->except($claimed)->concat($events->map(fn ($e) => \Illuminate\Support\Arr::except($e, 'claimed')))
            ->sortByDesc(fn ($i) => $i['date']?->timestamp ?? 0)->values();
    }

    /**
     * One correction as a sentence on a record's page, after «به پیشنهاد …،»: a list of parts, each a string
     * or a link (label, url). The record itself reads «این اثر», «این نسخه»…
     *
     * @return array<int, string|array{label: string, url: ?string}>
     */
    public static function line(array $item, Model $record): array
    {
        $s = $item['suggestion'];
        $self = 'این ' . SuggestionRegistry::typeLabel($record::class);
        $own = $s->suggestable_type === $record::class && (int) $s->suggestable_id === (int) $record->getKey();
        $resolution = $s->resolution ?? [];
        $quote = fn (?array $part) => $part ? ['label' => $part['label'], 'url' => $part['url']] : 'نامشخص';
        $apply = $s->relationDefinition()['apply'] ?? null;
        $workRef = function ($id, ?array $link) use ($record) {
            return $record instanceof \App\Models\Work && (int) $id === (int) $record->getKey() ? 'این اثر' : ['اثر ', $link ? ['label' => '«' . $link['label'] . '»', 'url' => $link['url']] : 'نامشخص'];
        };
        // the record the correction was made on, when it is not this one
        $where = $own || ! $item['record'] ? [] : [SuggestionRegistry::typeLabel($s->suggestable_type) . ' ', ['label' => '«' . $item['record']['label'] . '»', 'url' => $item['record']['url']], '، '];
        $flat = fn (array $parts) => array_values(array_filter(\Illuminate\Support\Arr::flatten(array_map(fn ($p) => is_array($p) && isset($p['label']) ? [$p] : $p, $parts), 1), fn ($p) => $p !== '' && $p !== null));

        if ($apply === 'move') {
            $from = $resolution['from_work_id'] ?? null;
            $to = $resolution['work_id'] ?? null;
            $subject = $own ? [$self] : ['نسخه‌ی ', ['label' => '«' . ($item['record']['label'] ?? 'نامشخص') . '»', 'url' => $item['record']['url'] ?? null]];

            return $flat([$subject, ' از ', $workRef($from, $item['before']), ' به ', $workRef($to, $item['after']), ' منتقل شد.']);
        }
        if ($apply === 'relocate') {
            $subject = $own ? [$self] : ['نسخه‌ی ', ['label' => '«' . ($item['record']['label'] ?? 'نامشخص') . '»', 'url' => $item['record']['url'] ?? null]];

            return $flat([$subject, $item['before'] ? [' از «', $item['before']['label'], '»'] : '', ' به ', $item['after'] ? ['«', $quote($item['after']), '»'] : 'جای تازه', ' منتقل شد.']);
        }
        if (in_array($apply, ['merge', 'merge_library', 'merge_people'], true)) {
            return [$item['text']];
        }
        if ($apply === 'split' && ! $own) {
            return $flat([$self . ' از تفکیک ', $where[1] ?? 'رکورد دیگری', ' ساخته شد.']);
        }
        if ($apply === 'record_relation') {
            $relation = self::recordRelation($s, $resolution, $s->relationDefinition()['target'] ?? null, (int) $record->getKey());

            return $flat(['رابطه ثبت شد: ', $relation['text'] ?? '', $relation['after'] ? [' «', $quote($relation['after']), '»'] : '']);
        }
        if ($s->kind === 'note' || $s->kind === 'general' || $s->review_queue) {
            return $flat([$where, $s->kind === 'note' ? $item['action'] . ': ' : '', $item['text'] ?? '']);
        }
        if ($origin = $s->machineOrigin()) {
            $kept = ['قول ' . $item['contributor']['name'] . ' برای «' . $item['action'] . '» نگه داشته شد: «', $item['after']['label'] ?? '', '»'];

            return $flat($origin === 'copy_merge'
                ? [$where, 'در یکی شدن دو معرفی ' . ($own ? $self : 'آن') . '، ', $kept]
                : [$where, 'در ادغام رکورد تکراری در ' . ($own ? $self : 'آن') . '، ', $kept]);
        }
        if ($s->kind === 'field') {
            $field = '«' . $item['action'] . '»';
            if ($s->standing && $s->standing !== 'adopted' && $s->standing !== 'edit') {
                return $flat([$where, 'برای ', $field, ' ' . ($item['standing'] ?? 'قول دیگر') . ' ثبت شد: «', $item['after']['label'] ?? '', '»']);
            }
            if (! $item['before']) {
                return $flat([$where, $field, ' با «', $item['after']['label'] ?? '', '» تکمیل شد.']);
            }

            return $flat([$where, $field, ' از «', $item['before']['label'], '» به «', $item['after']['label'] ?? 'خالی', '» اصلاح شد.']);
        }

        // contributors, roles, aliases, renames, the relation on its own page
        $after = $item['after'] ? $quote($item['after']) : null;

        return $flat([$where, $item['action'], $after || $item['before'] || $item['text'] ? ': ' : '.', $item['text'] ? $item['text'] . ($after ? ' ' : '') : '',
            $after ? ['«', $after, '»'] : '', $item['before'] ? [' (پیشین: «', $quote($item['before']), '»)'] : '']);
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
        $class = $type ? SuggestionRegistry::targetClass($type) : null;
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
        // not trim($text, '، '): trim cuts bytes, and the first byte of «،» is also that of «د», «ا»… (step 14.66)
        $text = preg_replace('/^[،\s]+|[،\s]+$/u', '', ($library ?? '') . ($shelfmark ? '، ش ' . $shelfmark : ''));

        return $text === '' ? null : $text;
    }

    /** «این اثر شرحی است بر اثر انتخاب‌شده» read from the record's side: the resolution keeps from/to, not the option picked. */
    private static function recordRelation(FieldSuggestion $s, array $resolution, ?string $target, ?int $viewer = null): array
    {
        $viewer ??= (int) $s->suggestable_id;
        $forward = (int) ($resolution['from'] ?? $s->suggestable_id) === $viewer;
        $other = $forward ? ($resolution['to'] ?? null) : ($resolution['from'] ?? null);
        $type = $resolution['type'] ?? ltrim((string) ($s->payload['role'] ?? ''), '~');
        $option = $type === '' ? null : (RecordRelations::options($s->suggestable_type)[($forward || in_array($type, RecordRelations::SYMMETRIC, true) ? '' : '~') . $type] ?? null);

        return [
            'after' => self::recordLink($target, $other ?? $s->payload['target_id'] ?? null),
            'text' => $option ? str_replace('انتخاب‌شده', 'زیر', $option) : null,
        ];
    }
}
