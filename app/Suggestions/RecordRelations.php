<?php

namespace App\Suggestions;

use App\Models\Manuscript;
use App\Models\Work;
use App\Support\WorkParts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Relations between works and between manuscripts. A row reads "{record} is a {type} of {related}";
 * each type has a label for both sides, so the relation shows on both record pages.
 */
class RecordRelations
{
    /** type => [label on the record's page, label on the related record's page] */
    public const WORK_TYPES = [
        'commentary' => ['شرحی است بر', 'شروح'],
        'gloss' => ['حاشیه‌ای است بر', 'حواشی'],
        'translation' => ['ترجمه‌ای است از', 'ترجمه‌ها'],
        'abridgment' => ['تلخیص، مختصر یا گزیده‌ای است از', 'تلخیص‌ها، مختصرها و گزیده‌ها'],
        'versification' => ['نظمی است از', 'منظومه‌ها'],
        'supplement' => ['تکمله یا ذیلی است بر', 'تکمله‌ها و ذیل‌ها'],
        'refutation' => ['ردیه یا نقدی است بر', 'ردیه‌ها و نقدها'],
        // The whole and its part: a chapter, a selection or a volume recorded as a work of its own. The part reads the
        // author, subject and language it lacks from the whole (WorkParts, step 14.308)
        'part' => ['بخش یا جلدی است از', 'بخش‌ها و مجلدها'],
        // Fankha's «وابسته به» of a kind not told (step 14.104, backlog item 6): a commentary on a commentary of the work, or
        // a title that names no kind
        'related' => ['وابسته است به', 'آثار وابسته'],
    ];

    public const MANUSCRIPT_TYPES = [
        'copied_from' => ['استنساخ‌شده از', 'نسخه‌هایی که از روی این نسخه استنساخ شده‌اند'],
        'collated_with' => ['مقابله‌شده با', 'نسخه‌هایی که با این نسخه مقابله شده‌اند'],
        'same_set' => ['مجلدهای دیگر از همان دوره', 'مجلدهای دیگر از همان دوره'],
        'facsimile_of' => ['نسخۀ عکسی یا میکروفیلمِ', 'نسخه‌های عکسی و میکروفیلم‌های این نسخه'],
        // Two descriptions that may be of one copy, kept apart until that is shown (step 14.16)
        'possibly_same' => ['احتمالاً همان نسخۀ', 'معرفی‌های دیگری که احتمالاً همین نسخه‌اند'],
        // A reader's answer to «possibly the same»: two copies, not one; it takes the possibly_same link away
        'distinct_from' => ['نسخۀ جدایی است، نه همانِ', 'نسخه‌های جدایی که با این نسخه یکی نیستند'],
    ];

    /** Types with no direction: one option, and a pair is the same either way round */
    public const SYMMETRIC = ['same_set', 'possibly_same', 'distinct_from'];

    /** @return array{0: string, 1: string, 2: string, 3: array} table, record key, related key, types */
    private static function shape(Model $record): array
    {
        return $record instanceof Manuscript
            ? ['manuscript_relations', 'manuscript_id', 'related_manuscript_id', self::MANUSCRIPT_TYPES]
            : ['work_relations', 'work_id', 'related_work_id', self::WORK_TYPES];
    }

    /**
     * The options a submitter picks from on a record's page: each type in both directions,
     * "type" (this record is a … of the chosen one) and "~type" (the chosen one is a … of this record).
     */
    public static function options(string $recordType): array
    {
        $types = SuggestionRegistry::typeKey($recordType) === 'manuscript' ? self::MANUSCRIPT_TYPES : self::WORK_TYPES;
        // Manuscript labels are participles and need «است» to read as a sentence
        [$noun, $chosen, $verb] = SuggestionRegistry::typeKey($recordType) === 'manuscript' ? ['نسخه', 'نسخۀ انتخاب‌شده', ' است'] : ['اثر', 'اثر انتخاب‌شده', ''];
        $options = [];
        foreach ($types as $type => [$forward]) {
            $options[$type] = match ($type) {
                'same_set' => "این {$noun} و {$chosen} مجلدهای یک دوره‌اند",
                'possibly_same' => "این {$noun} و {$chosen} احتمالاً یک نسخه‌اند",
                'distinct_from' => "این {$noun} و {$chosen} دو نسخۀ جدایند، نه یک نسخه",
                default => "این {$noun} {$forward} {$chosen}{$verb}",
            };
        }
        foreach ($types as $type => [$forward]) {
            if (! in_array($type, self::SYMMETRIC, true)) {
                $options['~' . $type] = "{$chosen} {$forward} این {$noun}{$verb}";
            }
        }

        return $options;
    }

    /**
     * An option's wording with the two records named (step 14.290): «این اثر تلخیص یا مختصری است از اثر انتخاب‌شده»
     * leaves the reader to work out which is which; ««صحة الاصول» تلخیص یا مختصری است از «هدایة الابرار»» does not.
     * A side with no name keeps its «این اثر» or «اثر انتخاب‌شده».
     */
    public static function named(string $recordType, string $label, ?string $thisName, ?string $chosenName): string
    {
        $manuscript = SuggestionRegistry::typeKey($recordType) === 'manuscript';
        $quote = fn (?string $name) => $name === null || trim($name) === '' ? null : '«' . \Illuminate\Support\Str::limit(trim($name), 70) . '»';

        return strtr($label, array_filter([
            $manuscript ? 'نسخۀ انتخاب‌شده' : 'اثر انتخاب‌شده' => $quote($chosenName),
            $manuscript ? 'این نسخه' : 'این اثر' => $quote($thisName),
        ]));
    }

    /** The sentence of one option for two records, or null when the option is not one of the type's. */
    public static function sentence(Model $record, Model $other, ?string $option): ?string
    {
        $label = self::options($record::class)[$option ?? ''] ?? null;

        return $label === null ? null : self::named($record::class, $label, SuggestionRegistry::describe($record)['label'], SuggestionRegistry::describe($other)['label']);
    }

    /** Adds "{a} is a {type} of {b}"; an option starting with "~" swaps the two. */
    public function add(Model $record, Model $other, string $option, ?string $note, ?int $suggestionId): array
    {
        [$table, $key, $relatedKey, $types] = self::shape($record);
        [$from, $to] = str_starts_with($option, '~') ? [$other, $record] : [$record, $other];
        $type = ltrim($option, '~');
        if (! isset($types[$type])) {
            throw new InvalidArgumentException('نوع رابطه نامعتبر است.');
        }
        if ($from->is($to)) {
            throw new InvalidArgumentException('یک رکورد نمی‌تواند با خودش رابطه داشته باشد.');
        }
        // A work is no part of itself, nor of one of its own parts (step 14.308)
        if ($from instanceof Work && $type === WorkParts::TYPE) {
            WorkParts::flush();
            if (WorkParts::wouldLoop($from->id, $to->id)) {
                throw new InvalidArgumentException('این رابطه اثر را بخشی از خودش می‌کند: «' . $to->primary_title . '» هم‌اکنون بخشی از «' . $from->primary_title . '» یا از بخش‌های آن است.');
            }
        }
        $exists = DB::table($table)->where('type', $type)->where(function ($q) use ($key, $relatedKey, $from, $to, $type) {
            $q->where([$key => $from->id, $relatedKey => $to->id]);
            // «same set» has no direction
            if (in_array($type, self::SYMMETRIC, true)) {
                $q->orWhere(fn ($q) => $q->where([$key => $to->id, $relatedKey => $from->id]));
            }
        })->exists();
        if ($exists) {
            throw new InvalidArgumentException('این رابطه هم‌اکنون ثبت شده است.');
        }

        // Two copies, not one: the link that said they may be one goes (and comes back on a rollback)
        $removed = [];
        if ($type === 'distinct_from') {
            $pair = fn ($q) => $q->where([$key => $from->id, $relatedKey => $to->id])->orWhere(fn ($q) => $q->where([$key => $to->id, $relatedKey => $from->id]));
            $removed = DB::table($table)->where('type', 'possibly_same')->where($pair)->get()->map(fn ($r) => (array) $r)->all();
            DB::table($table)->whereIn('id', array_column($removed, 'id'))->delete();
        }

        $id = DB::table($table)->insertGetId([
            $key => $from->id,
            $relatedKey => $to->id,
            'type' => $type,
            'note' => $note ? mb_substr($note, 0, 500) : null,
            'suggestion_id' => $suggestionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // The work made on another one is searched by that one's titles (Work::baseTitles, backlog item 95)
        // ...and a part by the author, subject and language of its whole, as are the parts below it (step 14.308)
        if ($from instanceof Work) {
            WorkParts::flush();
            $from->searchable();
            if ($type === WorkParts::TYPE) {
                WorkParts::reindexBelow($from->id);
            }
        }

        return [
            'old' => ['relations' => DB::table($table)->where($key, $record->id)->orWhere($relatedKey, $record->id)->count() - 1]
                + ($removed ? ['removed_record_relations' => ['table' => $table, 'rows' => $removed]] : []),
            'new' => ['added_record_relation' => ['table' => $table, 'id' => $id]],
            'resolution' => ['relation_id' => $id, 'type' => $type, 'from' => $from->id, 'to' => $to->id],
        ];
    }

    /**
     * Adds a work's relation with a work the site has no record of (work_text_relations, step 14.280): "{work} is a
     * {type} of {text}", or with an option starting with "~" the other way round.
     */
    public function addText(Work $work, string $option, string $text, ?string $note, ?int $suggestionId): array
    {
        $type = ltrim($option, '~');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (! isset(self::WORK_TYPES[$type])) {
            throw new InvalidArgumentException('نوع رابطه نامعتبر است.');
        }
        if ($text === '') {
            throw new InvalidArgumentException('عنوان اثر دیگر نوشته نشده است.');
        }
        $side = str_starts_with($option, '~') ? 'children' : 'related';
        if (DB::table('work_text_relations')->where(['work_id' => $work->id, 'side' => $side, 'type' => $type, 'text' => mb_substr($text, 0, 600)])->exists()) {
            throw new InvalidArgumentException('این رابطه هم‌اکنون ثبت شده است.');
        }
        $id = DB::table('work_text_relations')->insertGetId([
            'work_id' => $work->id, 'side' => $side, 'type' => $type, 'text' => mb_substr($text, 0, 600),
            'note' => $note ? mb_substr($note, 0, 500) : null, 'suggestion_id' => $suggestionId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'old' => ['text_relations' => DB::table('work_text_relations')->where('work_id', $work->id)->count() - 1],
            'new' => ['added_text_relation' => $id],
            'resolution' => ['text_relation_id' => $id, 'type' => $type, 'side' => $side, 'text' => mb_substr($text, 0, 600)],
        ];
    }

    /**
     * A work's relations with works the site has no record of, grouped by the label shown on its page.
     *
     * @return Collection<string, Collection<int, array{id: int, text: string, note: ?string, credit: ?string}>>
     */
    public static function texts(Work $work): Collection
    {
        // the rows added by hand; the catalogue's own entries (step 14.283) reach the page by WorkRelationLinker::pageRows
        $rows = DB::table('work_text_relations')->where('work_id', $work->id)->where('source', 'suggestion')->orderBy('id')->get();
        $credits = \App\Models\FieldSuggestion::whereIn('id', $rows->pluck('suggestion_id')->filter()->unique())
            ->where('status', 'approved')->pluck('credit_name', 'id');

        return $rows->filter(fn ($row) => isset(self::WORK_TYPES[$row->type]))->groupBy(fn ($row) => self::WORK_TYPES[$row->type][$row->side === 'related' ? 0 : 1])
            ->map(fn ($group) => $group->map(fn ($row) => ['id' => $row->id, 'text' => $row->text, 'note' => $row->note,
                'credit' => trim((string) ($row->suggestion_id ? $credits->get($row->suggestion_id) : '')) ?: null])->values());
    }

    /**
     * The record's relations in both directions, grouped by the label shown on its page.
     *
     * @return Collection<string, Collection<int, array{record: Model, note: ?string, credit: ?string}>>
     */
    public static function for(Model $record): Collection
    {
        [$table, $key, $relatedKey, $types] = self::shape($record);
        $class = $record::class;
        $rows = DB::table($table)->where($key, $record->id)->orWhere($relatedKey, $record->id)->orderBy('id')->get();
        $ids = $rows->flatMap(fn ($r) => [$r->{$key}, $r->{$relatedKey}])->reject(fn ($id) => $id == $record->id)->unique();
        $records = $class::whereIn('id', $ids)->get()->keyBy('id');
        // «به پیشنهاد …» beside a relation, only where the submitter allowed the name (backlog item 39)
        $credits = \App\Models\FieldSuggestion::whereIn('id', $rows->pluck('suggestion_id')->filter()->unique())
            ->where('status', 'approved')->pluck('credit_name', 'id');

        $groups = collect();
        foreach ($rows as $row) {
            $outgoing = (int) $row->{$key} === $record->id;
            $other = $records->get($outgoing ? $row->{$relatedKey} : $row->{$key});
            if (! $other || ! isset($types[$row->type])) {
                continue;
            }
            $label = $types[$row->type][$outgoing ? 0 : 1];
            $groups->put($label, ($groups->get($label) ?? collect())->push(['record' => $other, 'note' => $row->note,
                'credit' => trim((string) ($row->suggestion_id ? $credits->get($row->suggestion_id) : '')) ?: null]));
        }

        return $groups;
    }

    /**
     * After a merge: rows of the removed record point at the kept one; self-relations and duplicates go.
     * Returns what undoMove() needs: the rows repointed (id and column) and the rows deleted.
     */
    public static function moveOnMerge(Model $source, Model $target): array
    {
        [$table, $key, $relatedKey] = self::shape($source);
        $log = ['table' => $table, 'updated' => [], 'deleted' => [], 'texts' => []];
        // a work's relations with works that have no record go with it
        if ($source instanceof Work) {
            $log['texts'] = DB::table('work_text_relations')->where('work_id', $source->id)->pluck('id')->all();
            DB::table('work_text_relations')->whereIn('id', $log['texts'])->update(['work_id' => $target->id]);
        }
        foreach ([$key, $relatedKey] as $column) {
            foreach (DB::table($table)->where($column, $source->id)->get() as $row) {
                $from = $column === $key ? $target->id : $row->{$key};
                $to = $column === $relatedKey ? $target->id : $row->{$relatedKey};
                $duplicate = DB::table($table)->where([$key => $from, $relatedKey => $to, 'type' => $row->type])->where('id', '!=', $row->id)->exists();
                if ($from == $to || $duplicate) {
                    DB::table($table)->where('id', $row->id)->delete();
                    $log['deleted'][] = (array) $row;
                } else {
                    DB::table($table)->where('id', $row->id)->update([$column => $target->id]);
                    $log['updated'][] = [$row->id, $column];
                }
            }
        }
        if ($table === 'work_relations') {
            self::partsChanged([$target->id]);
        }

        return $log;
    }

    /**
     * After rows of work_relations were written outside add(): a merge, its undo, a rollback or a removed record. The
     * works named (the two ends of the rows) and the parts below them go to the search index again, since a «part»
     * row may be among the rows (step 14.308).
     *
     * @param  list<int>  $workIds
     */
    public static function partsChanged(array $workIds): void
    {
        WorkParts::flush();
        $ids = array_map('intval', $workIds);
        foreach ($workIds as $id) {
            array_push($ids, ...WorkParts::descendantIds((int) $id));
        }
        if ($ids) {
            Work::whereIn('id', array_values(array_unique($ids)))->get()->searchable();
        }
    }

    /** Points the rows moveOnMerge() changed back at the restored record and restores the rows it deleted. */
    public static function undoMove(array $log, int $sourceId): void
    {
        if (! $log) {
            return;
        }
        foreach ($log['updated'] as [$id, $column]) {
            DB::table($log['table'])->where('id', $id)->update([$column => $sourceId]);
        }
        foreach ($log['deleted'] as $row) {
            DB::table($log['table'])->insertOrIgnore($row);
        }
        DB::table('work_text_relations')->whereIn('id', $log['texts'] ?? [])->update(['work_id' => $sourceId]);
        if ($log['table'] === 'work_relations') {
            $rows = DB::table('work_relations')->whereIn('id', [...array_column($log['updated'], 0), ...array_column($log['deleted'], 'id')])->get(['work_id', 'related_work_id']);
            self::partsChanged([$sourceId, ...$rows->pluck('work_id')->all(), ...$rows->pluck('related_work_id')->all()]);
        }
    }
}
