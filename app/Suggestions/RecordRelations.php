<?php

namespace App\Suggestions;

use App\Models\Manuscript;
use App\Models\Work;
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
        'abridgment' => ['تلخیص یا مختصری است از', 'تلخیص‌ها و مختصرها'],
        'versification' => ['نظمی است از', 'منظومه‌ها'],
        'supplement' => ['تکمله یا ذیلی است بر', 'تکمله‌ها و ذیل‌ها'],
        'refutation' => ['ردیه یا نقدی است بر', 'ردیه‌ها و نقدها'],
        'part' => ['بخش یا جلدی است از', 'بخش‌ها و مجلدها'],
    ];

    public const MANUSCRIPT_TYPES = [
        'copied_from' => ['استنساخ‌شده از', 'نسخه‌هایی که از روی این نسخه استنساخ شده‌اند'],
        'collated_with' => ['مقابله‌شده با', 'نسخه‌هایی که با این نسخه مقابله شده‌اند'],
        'same_set' => ['مجلدهای دیگر از همان دوره', 'مجلدهای دیگر از همان دوره'],
        'facsimile_of' => ['نسخه‌ی عکسی یا میکروفیلمِ', 'نسخه‌های عکسی و میکروفیلم‌های این نسخه'],
    ];

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
        [$noun, $chosen, $verb] = SuggestionRegistry::typeKey($recordType) === 'manuscript' ? ['نسخه', 'نسخه‌ی انتخاب‌شده', ' است'] : ['اثر', 'اثر انتخاب‌شده', ''];
        $options = [];
        foreach ($types as $type => [$forward]) {
            $options[$type] = $type === 'same_set' ? "این {$noun} و {$chosen} مجلدهای یک دوره‌اند" : "این {$noun} {$forward} {$chosen}{$verb}";
        }
        foreach ($types as $type => [$forward]) {
            if ($type !== 'same_set') {
                $options['~' . $type] = "{$chosen} {$forward} این {$noun}{$verb}";
            }
        }

        return $options;
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
        $exists = DB::table($table)->where('type', $type)->where(function ($q) use ($key, $relatedKey, $from, $to, $type) {
            $q->where([$key => $from->id, $relatedKey => $to->id]);
            // «same set» has no direction
            if ($type === 'same_set') {
                $q->orWhere(fn ($q) => $q->where([$key => $to->id, $relatedKey => $from->id]));
            }
        })->exists();
        if ($exists) {
            throw new InvalidArgumentException('این رابطه هم‌اکنون ثبت شده است.');
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
        if ($from instanceof Work) {
            $from->searchable();
        }

        return [
            'old' => ['relations' => DB::table($table)->where($key, $record->id)->orWhere($relatedKey, $record->id)->count() - 1],
            'new' => ['added_record_relation' => ['table' => $table, 'id' => $id]],
            'resolution' => ['relation_id' => $id, 'type' => $type, 'from' => $from->id, 'to' => $to->id],
        ];
    }

    /**
     * The record's relations in both directions, grouped by the label shown on its page.
     *
     * @return Collection<string, Collection<int, array{record: Model, note: ?string}>>
     */
    public static function for(Model $record): Collection
    {
        [$table, $key, $relatedKey, $types] = self::shape($record);
        $class = $record::class;
        $rows = DB::table($table)->where($key, $record->id)->orWhere($relatedKey, $record->id)->orderBy('id')->get();
        $ids = $rows->flatMap(fn ($r) => [$r->{$key}, $r->{$relatedKey}])->reject(fn ($id) => $id == $record->id)->unique();
        $records = $class::whereIn('id', $ids)->get()->keyBy('id');

        $groups = collect();
        foreach ($rows as $row) {
            $outgoing = (int) $row->{$key} === $record->id;
            $other = $records->get($outgoing ? $row->{$relatedKey} : $row->{$key});
            if (! $other || ! isset($types[$row->type])) {
                continue;
            }
            $label = $types[$row->type][$outgoing ? 0 : 1];
            $groups->put($label, ($groups->get($label) ?? collect())->push(['record' => $other, 'note' => $row->note]));
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
        $log = ['table' => $table, 'updated' => [], 'deleted' => []];
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

        return $log;
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
    }
}
