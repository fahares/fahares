<?php

namespace App\Suggestions;

use App\Models\Catalog;
use App\Models\EntityRedirect;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies approved relational suggestions: moving a manuscript to another work and folding
 * a duplicate manuscript or work into the record it repeats. Every denormalized counter the
 * change touches is recounted. Runs inside the reviewer's transaction.
 */
class RecordMerger
{
    // Descriptive manuscript columns the kept record inherits when its own value is empty
    private const MANUSCRIPT_FILL = [
        'scribe_id', 'scribe_name', 'copy_date_raw', 'copy_date_hijri_year', 'copy_place', 'commissioned_by',
        'script_names', 'script_style', 'folios', 'lines', 'dimensions', 'paper', 'binding',
        'incipit_text', 'explicit_text',
    ];

    /** @return array{old: array, new: array} */
    public function moveManuscript(Manuscript $manuscript, Work $target): array
    {
        $from = $manuscript->work_id;
        if ($from === $target->id) {
            throw new InvalidArgumentException('این نسخه هم‌اکنون ذیل همین اثر است.');
        }

        $manuscript->update(['work_id' => $target->id]);
        $this->recountWorks([$from, $target->id]);

        return ['old' => ['work_id' => $from], 'new' => ['work_id' => $target->id]];
    }

    /** Folds $source into $target and deletes it behind a permanent redirect. */
    public function mergeManuscript(Manuscript $source, Manuscript $target): array
    {
        if ($source->is($target)) {
            throw new InvalidArgumentException('رکورد مبدأ و مقصد یکی است.');
        }
        $snapshot = $this->snapshot($source, ['manuscript_scribe' => 'manuscript_id', 'manuscript_script' => 'manuscript_id', 'manuscript_people' => 'manuscript_id']);

        $filled = [];
        foreach (self::MANUSCRIPT_FILL as $column) {
            if (($target->{$column} === null || $target->{$column} === '') && $source->{$column} !== null && $source->{$column} !== '') {
                $filled[$column] = $source->{$column};
            }
        }
        if ($filled) {
            $target->forceFill($filled)->save();
        }
        // Scribes the kept copy does not list yet
        $position = (int) DB::table('manuscript_scribe')->where('manuscript_id', $target->id)->max('position');
        foreach (DB::table('manuscript_scribe')->where('manuscript_id', $source->id)->orderBy('position')->get() as $row) {
            // An unlinked name keeps its text
            DB::table('manuscript_scribe')->insertOrIgnore(['manuscript_id' => $target->id, 'person_id' => $row->person_id, 'name' => $row->name ?? null, 'position' => ++$position, 'note' => $row->note]);
        }
        // Owners, endowers, correctors… and relations with other copies move to the kept copy
        DB::table('manuscript_people')->where('manuscript_id', $source->id)->update(['manuscript_id' => $target->id]);
        RecordRelations::moveOnMerge($source, $target);

        $this->moveAttachments($source, $target);
        $this->citeEntry($source, $target);

        $counters = $source->only(['work_id', 'library_id', 'catalog_volume_id', 'cataloger_id']);
        $people = DB::table('manuscript_scribe')->where('manuscript_id', $source->id)->pluck('person_id')->all();
        $scripts = DB::table('manuscript_script')->where('manuscript_id', $source->id)->pluck('script_id')->all();

        EntityRedirect::recordRedirect('manuscripts', $source->id, $target->id);
        $source->delete();

        $this->recountWorks([$counters['work_id'], $target->work_id]);
        $this->recountManuscriptOwners($counters, $scripts);
        $this->recountPeople(array_merge($people, DB::table('manuscript_scribe')->where('manuscript_id', $target->id)->pluck('person_id')->all()));
        $target->searchable();

        return ['source_snapshot' => $snapshot, 'filled' => $filled];
    }

    /** Moves the duplicate work's manuscripts under $target (folding identical copies) and deletes it. */
    public function mergeWork(Work $source, Work $target): array
    {
        if ($source->is($target)) {
            throw new InvalidArgumentException('رکورد مبدأ و مقصد یکی است.');
        }
        $snapshot = $this->snapshot($source, ['work_author' => 'work_id', 'subject_work' => 'work_id', 'language_work' => 'work_id']);

        $targetByKey = $target->manuscripts()->get()->keyBy(fn (Manuscript $m) => $this->copyKey($m));
        $moved = [];
        $folded = [];
        foreach ($source->manuscripts()->get() as $manuscript) {
            if ($existing = $targetByKey->get($this->copyKey($manuscript))) {
                $this->mergeManuscript($manuscript, $existing);
                $folded[$manuscript->id] = $existing->id;
            } else {
                $manuscript->update(['work_id' => $target->id]);
                $moved[] = $manuscript->id;
            }
        }

        // The duplicate's title becomes an alternative title of the kept work
        $titles = (array) ($target->alternative_titles ?? []);
        $known = array_map(fn ($t) => is_array($t) ? ($t['title'] ?? '') : (string) $t, $titles);
        $known[] = $target->primary_title;
        foreach (array_merge([['title' => $source->primary_title]], (array) ($source->alternative_titles ?? [])) as $title) {
            $text = is_array($title) ? ($title['title'] ?? '') : (string) $title;
            if ($text !== '' && ! in_array($text, $known, true)) {
                $titles[] = is_array($title) ? $title : ['title' => $text];
                $known[] = $text;
            }
        }

        $fill = ['alternative_titles' => $titles ?: null];
        foreach (['author_id', 'author_name', 'composition_date_raw', 'composition_year_hijri', 'composition_place', 'description', 'incipit_text', 'explicit_text'] as $column) {
            if (($target->{$column} === null || $target->{$column} === '') && $source->{$column} !== null && $source->{$column} !== '') {
                $fill[$column] = $source->{$column};
            }
        }
        $target->forceFill($fill)->save();

        RecordRelations::moveOnMerge($source, $target);
        $target->subjects()->syncWithoutDetaching($source->subjects()->pluck('subjects.id')->all());
        $target->languages()->syncWithoutDetaching($source->languages()->pluck('languages.id')->all());

        $this->moveAttachments($source, $target);
        $this->citeEntry($source, $target);

        $people = DB::table('work_author')->whereIn('work_id', [$source->id, $target->id])->pluck('person_id')->all();
        $subjects = DB::table('subject_work')->whereIn('work_id', [$source->id, $target->id])->pluck('subject_id')->all();
        $languages = DB::table('language_work')->whereIn('work_id', [$source->id, $target->id])->pluck('language_id')->all();

        EntityRedirect::recordRedirect('works', $source->id, $target->id);
        $source->delete();

        $this->recountWorks([$target->id]);
        $this->recountPeople($people);
        $this->recountSubjects($subjects);
        foreach (array_unique($languages) as $id) {
            DB::table('languages')->where('id', $id)->update(['works_count' => DB::table('language_work')->where('language_id', $id)->count()]);
        }
        Manuscript::whereIn('id', $moved)->searchable();
        $target->searchable();

        return ['source_snapshot' => $snapshot, 'moved_manuscript_ids' => $moved, 'folded_manuscript_ids' => $folded];
    }

    /** Same library and shelfmark: the same physical copy (rule used by the addenda reconciliation) */
    private function copyKey(Manuscript $m): string
    {
        return \App\Helpers\TextNormalizer::normalize($m->city) . '|' . \App\Helpers\TextNormalizer::normalize($m->library) . '|'
            . preg_replace('/\s+/u', '', \App\Helpers\TextNormalizer::normalize($m->shelfmark));
    }

    /** Source citations and still-open suggestions follow the record that is kept. */
    private function moveAttachments($source, $target): void
    {
        $source->sourceCitations()->update(['citable_id' => $target->id]);
        FieldSuggestion::where('suggestable_type', $source::class)->where('suggestable_id', $source->id)
            ->where('status', 'pending')->update(['suggestable_id' => $target->id]);
    }

    /** The duplicate's own catalog entry stays citable on the kept record. */
    private function citeEntry($source, $target): void
    {
        $catalogId = $source->catalog_id ?? Catalog::where('code', 'fankha')->value('id');
        if (! $catalogId || ! $source->volume_number) {
            return;
        }
        $target->sourceCitations()->firstOrCreate(
            ['catalog_id' => $catalogId, 'volume_number' => $source->volume_number, 'page_start' => $source->page_start, 'role' => 'entry'],
            ['page_end' => $source->page_end, 'raw_text' => $source->raw_text]
        );
    }

    private function snapshot($record, array $pivots): array
    {
        $rows = [];
        foreach ($pivots as $table => $key) {
            $rows[$table] = DB::table($table)->where($key, $record->id)->get()->map(fn ($r) => (array) $r)->all();
        }

        return ['attributes' => $record->getAttributes(), 'pivots' => $rows];
    }

    private function recountWorks(array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            Work::find($id)?->update(['manuscripts_count' => Manuscript::where('work_id', $id)->count()]);
        }
    }

    private function recountPeople(array $ids): void
    {
        foreach (Person::whereIn('id', array_unique(array_filter($ids)))->get() as $person) {
            $person->recountContributions();
        }
    }

    private function recountSubjects(array $ids): void
    {
        $ids = array_unique($ids);
        foreach ($ids as $id) {
            DB::table('subjects')->where('id', $id)->update(['works_count' => DB::table('subject_work')->where('subject_id', $id)->count()]);
        }
        // Parent subjects carry the distinct count of their own and their children's works
        foreach (DB::table('subjects')->whereIn('id', $ids)->whereNotNull('parent_id')->pluck('parent_id')->unique() as $parentId) {
            $family = DB::table('subjects')->where('parent_id', $parentId)->pluck('id')->push($parentId)->all();
            DB::table('subjects')->where('id', $parentId)->update(['works_count' => DB::table('subject_work')->whereIn('subject_id', $family)->distinct()->count('work_id')]);
        }
    }

    private function recountManuscriptOwners(array $counters, array $scriptIds): void
    {
        foreach (['library_id' => 'libraries', 'catalog_volume_id' => 'catalog_volumes', 'cataloger_id' => 'catalogers'] as $column => $table) {
            if ($id = $counters[$column] ?? null) {
                DB::table($table)->where('id', $id)->update(['manuscripts_count' => Manuscript::where($column, $id)->count()]);
            }
        }
        foreach (array_unique($scriptIds) as $id) {
            DB::table('scripts')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscript_script')->where('script_id', $id)->count()]);
        }
    }
}
