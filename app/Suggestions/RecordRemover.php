<?php

namespace App\Suggestions;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies the relational suggestion «redundant»: a manuscript, work or person record that should not
 * exist (e.g. a heading or a note the parser read as an entry) is deleted. A work or person is removed
 * only once nothing depends on it; the reviewer moves or merges its manuscripts and works first.
 * remove() returns a full snapshot, kept in the audit log ("remove"), and undo() restores it with its id.
 */
class RecordRemover
{
    /** Pivot tables deleted with the record (cascade), by record type: table => key column */
    private const PIVOTS = [
        Manuscript::class => ['manuscript_scribe' => 'manuscript_id', 'manuscript_script' => 'manuscript_id', 'manuscript_people' => 'manuscript_id'],
        Work::class => ['work_author' => 'work_id', 'subject_work' => 'work_id', 'language_work' => 'work_id'],
        Person::class => [],
    ];

    private const RELATIONS = [
        Manuscript::class => ['manuscript_relations', 'manuscript_id', 'related_manuscript_id'],
        Work::class => ['work_relations', 'work_id', 'related_work_id'],
    ];

    /**
     * What still depends on the record and must be moved first, as labelled counts; empty when it can go.
     *
     * @return array<string, int>
     */
    public function blockers(Model $record): array
    {
        $counts = match ($record::class) {
            Work::class => ['نسخه' => Manuscript::where('work_id', $record->id)->count()],
            Person::class => [
                'اثر' => DB::table('work_author')->where('person_id', $record->id)->count() + Work::where('author_id', $record->id)->whereNotIn('id', DB::table('work_author')->where('person_id', $record->id)->select('work_id'))->count(),
                'نسخه (کاتب)' => DB::table('manuscript_scribe')->where('person_id', $record->id)->count() + Manuscript::where('scribe_id', $record->id)->whereNotIn('id', DB::table('manuscript_scribe')->where('person_id', $record->id)->select('manuscript_id'))->count(),
                'نسخه (نقش‌های دیگر)' => DB::table('manuscript_people')->where('person_id', $record->id)->count(),
                'فهرست‌نگار' => DB::table('catalogers')->where('person_id', $record->id)->count(),
            ],
            default => [],
        };

        return array_filter($counts);
    }

    public function remove(Model $record): array
    {
        if ($blockers = $this->blockers($record)) {
            $list = implode('، ', array_map(fn ($label, $n) => "{$n} {$label}", array_keys($blockers), $blockers));
            throw new InvalidArgumentException("این رکورد هنوز به {$list} پیوند دارد. نخست آن‌ها را منتقل یا ادغام کنید.");
        }

        $pivots = [];
        foreach (self::PIVOTS[$record::class] ?? [] as $table => $key) {
            $pivots[$table] = DB::table($table)->where($key, $record->id)->get()->map(fn ($r) => (array) $r)->all();
        }
        $relations = [];
        if (isset(self::RELATIONS[$record::class])) {
            [$table, $key, $relatedKey] = self::RELATIONS[$record::class];
            $relations = ['table' => $table, 'rows' => DB::table($table)->where($key, $record->id)->orWhere($relatedKey, $record->id)->get()->map(fn ($r) => (array) $r)->all()];
        }
        $referrals = $record instanceof Work ? DB::table('referrals')->where('target_work_id', $record->id)->pluck('id')->all() : [];

        $record->delete();
        $this->recount($record::class, $record->getAttributes(), $pivots);

        return ['snapshot' => ['attributes' => $record->getAttributes(), 'pivots' => $pivots], 'relations' => $relations, 'referral_ids' => $referrals];
    }

    /** Restores what remove() deleted, from the values it returned. */
    public function undo(string $class, array $old): Model
    {
        $attributes = $old['snapshot']['attributes'] ?? throw new InvalidArgumentException('اطلاعات رکورد حذف‌شده در این لاگ ثبت نشده است.');
        if ($class::find($attributes['id'])) {
            throw new InvalidArgumentException("رکوردی با شناسه‌ی #{$attributes['id']} هم‌اکنون وجود دارد.");
        }
        if ($class === Manuscript::class && ! Work::find($attributes['work_id'])) {
            throw new InvalidArgumentException("اثر این نسخه (#{$attributes['work_id']}) دیگر وجود ندارد.");
        }
        foreach (['author_id' => 'پدیدآور', 'scribe_id' => 'کاتب'] as $column => $label) {
            if (! empty($attributes[$column]) && ! Person::find($attributes[$column])) {
                throw new InvalidArgumentException("{$label} این رکورد (شخص #{$attributes[$column]}) دیگر وجود ندارد. نخست آن را بازگردانید.");
            }
        }
        DB::table((new $class)->getTable())->insert(array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $attributes));
        foreach ($old['snapshot']['pivots'] ?? [] as $table => $rows) {
            DB::table($table)->insertOrIgnore($rows);
        }
        if ($relations = $old['relations'] ?? null) {
            DB::table($relations['table'])->insertOrIgnore($relations['rows']);
        }
        DB::table('referrals')->whereIn('id', $old['referral_ids'] ?? [])->whereNull('target_work_id')->update(['target_work_id' => $attributes['id']]);

        $record = $class::findOrFail($attributes['id']);
        $this->recount($class, $attributes, $old['snapshot']['pivots'] ?? []);
        $record->searchable();

        return $record;
    }

    /** The audit log entry of a removed record, for the "removed" page at its former address. */
    public static function removal(string $class, int $id): ?AuditLog
    {
        return AuditLog::where('auditable_type', $class)->where('auditable_id', $id)->where('action', 'remove')->latest('id')->first();
    }

    /**
     * The response at the former address of a removed record: a removed manuscript leads to its work,
     * anything else gets the "removed" page (410). Null when the record was not removed.
     */
    public static function goneResponse(string $class, int $id)
    {
        $log = self::removal($class, $id);
        if (! $log) {
            return null;
        }
        $attributes = $log->old_values['snapshot']['attributes'] ?? [];
        if ($class === Manuscript::class && ($work = Work::find($attributes['work_id'] ?? 0))) {
            return redirect()->route('works.show', $work);
        }
        [$label, $title] = match ($class) {
            Manuscript::class => ['نسخه', $attributes['shelfmark'] ?? ('#' . $id)],
            Work::class => ['اثر', $attributes['primary_title'] ?? ('#' . $id)],
            default => ['شخص', $attributes['name'] ?? ('#' . $id)],
        };

        return response()->view('records.removed', compact('label', 'title'), 410)->header('X-Robots-Tag', 'noindex');
    }

    private function recount(string $class, array $attributes, array $pivots): void
    {
        if ($class === Manuscript::class) {
            Work::find($attributes['work_id'])?->update(['manuscripts_count' => Manuscript::where('work_id', $attributes['work_id'])->count()]);
            Work::whereKey($attributes['work_id'])->searchable();
            foreach (['library_id' => 'libraries', 'catalog_volume_id' => 'catalog_volumes', 'cataloger_id' => 'catalogers'] as $column => $table) {
                if ($id = $attributes[$column] ?? null) {
                    DB::table($table)->where('id', $id)->update(['manuscripts_count' => Manuscript::where($column, $id)->count()]);
                }
            }
            foreach (array_column($pivots['manuscript_script'] ?? [], 'script_id') as $id) {
                DB::table('scripts')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscript_script')->where('script_id', $id)->count()]);
            }
            $people = array_column($pivots['manuscript_scribe'] ?? [], 'person_id');
        } elseif ($class === Work::class) {
            $subjects = array_column($pivots['subject_work'] ?? [], 'subject_id');
            foreach ($subjects as $id) {
                DB::table('subjects')->where('id', $id)->update(['works_count' => DB::table('subject_work')->where('subject_id', $id)->count()]);
            }
            // Parent subjects carry the distinct count of their own and their children's works
            foreach (DB::table('subjects')->whereIn('id', $subjects)->whereNotNull('parent_id')->pluck('parent_id')->unique() as $parentId) {
                $family = DB::table('subjects')->where('parent_id', $parentId)->pluck('id')->push($parentId)->all();
                DB::table('subjects')->where('id', $parentId)->update(['works_count' => DB::table('subject_work')->whereIn('subject_id', $family)->distinct()->count('work_id')]);
            }
            foreach (array_column($pivots['language_work'] ?? [], 'language_id') as $id) {
                DB::table('languages')->where('id', $id)->update(['works_count' => DB::table('language_work')->where('language_id', $id)->count()]);
            }
            $people = array_column($pivots['work_author'] ?? [], 'person_id');
        }
        foreach (Person::whereIn('id', array_filter($people ?? []))->get() as $person) {
            $person->recountContributions();
        }
    }
}
