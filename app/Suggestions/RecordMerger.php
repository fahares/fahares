<?php

namespace App\Suggestions;

use App\Models\EntityRedirect;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies approved relational suggestions: moving a manuscript to another work and folding
 * a duplicate manuscript or work into the record it repeats. Every denormalized counter the
 * change touches is recounted. Runs inside the reviewer's transaction.
 *
 * A merge returns everything it changed (kept in the audit log), and undoManuscriptMerge() /
 * undoWorkMerge() put the removed record back from it with its own id.
 */
class RecordMerger
{
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

    /**
     * Folds $source into $target and deletes it behind a permanent redirect. $fields holds the admin's choice for each
     * field the two records hold differently (MergeComparison); a field not chosen takes the default.
     */
    public function mergeManuscript(Manuscript $source, Manuscript $target, ?array $fields = null): array
    {
        if ($source->is($target)) {
            throw new InvalidArgumentException('رکورد مبدأ و مقصد یکی است.');
        }
        $snapshot = $this->snapshot($source, ['manuscript_scribe' => 'manuscript_id', 'manuscript_script' => 'manuscript_id', 'manuscript_people' => 'manuscript_id']);

        $compared = MergeComparison::apply($source, $target, $fields);
        $target->save();
        // Scribes the kept copy does not list yet
        $addedScribes = [];
        $position = (int) DB::table('manuscript_scribe')->where('manuscript_id', $target->id)->max('position');
        foreach (DB::table('manuscript_scribe')->where('manuscript_id', $source->id)->orderBy('position')->get() as $row) {
            if ($row->person_id && DB::table('manuscript_scribe')->where(['manuscript_id' => $target->id, 'person_id' => $row->person_id])->exists()) {
                continue;
            }
            // An unlinked name keeps its text
            $addedScribes[] = DB::table('manuscript_scribe')->insertGetId(['manuscript_id' => $target->id, 'person_id' => $row->person_id, 'name' => $row->name ?? null, 'position' => ++$position, 'note' => $row->note]);
        }
        // Owners, endowers, correctors… and relations with other copies move to the kept copy
        DB::table('manuscript_people')->where('manuscript_id', $source->id)->update(['manuscript_id' => $target->id]);
        $relations = RecordRelations::moveOnMerge($source, $target);

        $attachments = $this->moveAttachments($source, $target);

        $counters = $source->only(['work_id', 'library_id', 'catalog_volume_id', 'cataloger_id']);
        $people = DB::table('manuscript_scribe')->where('manuscript_id', $source->id)->pluck('person_id')->all();
        $scripts = DB::table('manuscript_script')->where('manuscript_id', $source->id)->pluck('script_id')->all();

        $redirects = $this->redirect('manuscripts', $source->id, $target->id);
        $source->delete();

        $this->recountWorks([$counters['work_id'], $target->work_id]);
        $this->recountManuscriptOwners($counters, $scripts);
        $this->recountPeople(array_merge($people, DB::table('manuscript_scribe')->where('manuscript_id', $target->id)->pluck('person_id')->all()));
        $target->searchable();

        return [
            'source_snapshot' => $snapshot,
            'fields' => $compared,
            'added_scribe_ids' => $addedScribes,
            'relations' => $relations,
            'redirects' => $redirects,
        ] + $attachments;
    }

    /** Puts back a manuscript mergeManuscript() removed, from the values it returned. */
    public function undoManuscriptMerge(Manuscript $target, array $old): Manuscript
    {
        $snapshot = $old['source_snapshot'] ?? null;
        if (! $snapshot || ! array_key_exists('added_scribe_ids', $old)) {
            throw new InvalidArgumentException('این ادغام پیش از امکان بازگردانی خودکار ثبت شده است. اطلاعات کامل نسخه‌ی حذف‌شده در همین لاگ (source_snapshot) هست.');
        }
        $source = $this->restore(Manuscript::class, 'manuscripts', $snapshot['attributes']);
        foreach (['manuscript_scribe', 'manuscript_script'] as $table) {
            DB::table($table)->insertOrIgnore($snapshot['pivots'][$table] ?? []);
        }
        DB::table('manuscript_people')->whereIn('id', array_column($snapshot['pivots']['manuscript_people'] ?? [], 'id'))->update(['manuscript_id' => $source->id]);
        DB::table('manuscript_scribe')->whereIn('id', $old['added_scribe_ids'])->delete();
        // A merge of before backlog item 46 filled empty columns only
        $this->unfill($target, $old['filled'] ?? []);
        MergeComparison::undo($target, $old['fields'] ?? []);
        $target->save();
        RecordRelations::undoMove($old['relations'] ?? [], $source->id);
        $this->undoAttachments($source, $target, $old);
        $this->undoRedirect('manuscripts', $source->id, $old['redirects'] ?? []);

        $this->recountWorks([$source->work_id, $target->work_id]);
        $scripts = array_column($snapshot['pivots']['manuscript_script'] ?? [], 'script_id');
        $this->recountManuscriptOwners($source->only(['library_id', 'catalog_volume_id', 'cataloger_id']), $scripts);
        $this->recountPeople(DB::table('manuscript_scribe')->whereIn('manuscript_id', [$source->id, $target->id])->pluck('person_id')->all());
        $source->searchable();
        $target->searchable();
        // The work's index carries its copies' incipits and explicits
        Work::whereIn('id', array_unique([$source->work_id, $target->work_id]))->searchable();

        return $source;
    }

    /**
     * Moves the duplicate work's manuscripts under $target (folding identical copies, each with the default choices)
     * and deletes it. $fields as in mergeManuscript().
     */
    public function mergeWork(Work $source, Work $target, ?array $fields = null): array
    {
        if ($source->is($target)) {
            throw new InvalidArgumentException('رکورد مبدأ و مقصد یکی است.');
        }
        $snapshot = $this->snapshot($source, ['work_author' => 'work_id', 'subject_work' => 'work_id', 'language_work' => 'work_id', 'work_people' => 'work_id']);
        $targetBefore = $target->only(['alternative_titles']);
        // Compared before the copies move, on the two works as the admin saw them
        $compared = MergeComparison::apply($source, $target, $fields);

        $targetByKey = $target->manuscripts()->get()->keyBy(fn (Manuscript $m) => $this->copyKey($m));
        $moved = [];
        $folded = [];
        foreach ($source->manuscripts()->get() as $manuscript) {
            if ($existing = $targetByKey->get($this->copyKey($manuscript))) {
                $folded[$manuscript->id] = ['target_id' => $existing->id] + $this->mergeManuscript($manuscript, $existing);
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

        $target->forceFill(['alternative_titles' => $titles ?: null])->save();

        // Translators, dedicatees… move to the kept work, unless it lists the same person in the same role
        $people = DB::table('work_people')->where('work_id', $source->id)->get();
        $movedPeople = $people->reject(fn ($row) => DB::table('work_people')->where('work_id', $target->id)->where('role', $row->role)
            ->where(fn ($q) => $row->person_id ? $q->where('person_id', $row->person_id) : $q->whereNull('person_id')->where('name', $row->name))->exists())->pluck('id')->all();
        DB::table('work_people')->whereIn('id', $movedPeople)->update(['work_id' => $target->id]);

        $relations = RecordRelations::moveOnMerge($source, $target);
        $subjects = array_diff($source->subjects()->pluck('subjects.id')->all(), $target->subjects()->pluck('subjects.id')->all());
        $languages = array_diff($source->languages()->pluck('languages.id')->all(), $target->languages()->pluck('languages.id')->all());
        $target->subjects()->syncWithoutDetaching($subjects);
        $target->languages()->syncWithoutDetaching($languages);
        // «See …» cross-references would lose their link when the duplicate is deleted
        $referrals = DB::table('referrals')->where('target_work_id', $source->id)->pluck('id')->all();
        DB::table('referrals')->whereIn('id', $referrals)->update(['target_work_id' => $target->id]);

        $attachments = $this->moveAttachments($source, $target);

        $people = DB::table('work_author')->whereIn('work_id', [$source->id, $target->id])->pluck('person_id')->all();
        $subjectIds = DB::table('subject_work')->whereIn('work_id', [$source->id, $target->id])->pluck('subject_id')->all();
        $languageIds = DB::table('language_work')->whereIn('work_id', [$source->id, $target->id])->pluck('language_id')->all();

        $redirects = $this->redirect('works', $source->id, $target->id);
        $source->delete();

        $this->recountWorks([$target->id]);
        $this->recountPeople($people);
        $this->recountSubjects($subjectIds);
        $this->recountLanguages($languageIds);
        Manuscript::whereIn('id', $moved)->searchable();
        $target->searchable();

        return [
            'source_snapshot' => $snapshot,
            'moved_manuscript_ids' => $moved,
            'folded_manuscripts' => $folded,
            'target_before' => $targetBefore,
            'target_after' => $target->only(array_keys($targetBefore)),
            'fields' => $compared,
            'added_subject_ids' => array_values($subjects),
            'added_language_ids' => array_values($languages),
            'referral_ids' => $referrals,
            'moved_people_ids' => $movedPeople,
            'relations' => $relations,
            'redirects' => $redirects,
        ] + $attachments;
    }

    /** Puts back a work mergeWork() removed, with its manuscripts, from the values it returned. */
    public function undoWorkMerge(Work $target, array $old): Work
    {
        $snapshot = $old['source_snapshot'] ?? null;
        if (! $snapshot || ! array_key_exists('folded_manuscripts', $old)) {
            throw new InvalidArgumentException('این ادغام پیش از امکان بازگردانی خودکار ثبت شده است. اطلاعات کامل اثر حذف‌شده در همین لاگ (source_snapshot) هست.');
        }
        $source = $this->restore(Work::class, 'works', $snapshot['attributes']);
        foreach (['work_author', 'subject_work', 'language_work'] as $table) {
            DB::table($table)->insertOrIgnore($snapshot['pivots'][$table] ?? []);
        }
        // Moved people rows come back; the ones the kept work already listed were deleted with the duplicate
        DB::table('work_people')->whereIn('id', $old['moved_people_ids'] ?? [])->update(['work_id' => $source->id]);
        DB::table('work_people')->insertOrIgnore($snapshot['pivots']['work_people'] ?? []);

        // Copies folded into one of the kept work's copies come back first (they belong to the restored work)
        foreach (array_reverse($old['folded_manuscripts'], true) as $data) {
            $kept = Manuscript::find($data['target_id'])
                ?? throw new InvalidArgumentException("نسخه‌ی #{$data['target_id']}، که نسخه‌ای در آن ادغام شده بود، دیگر وجود ندارد.");
            $this->undoManuscriptMerge($kept, $data);
        }
        Manuscript::whereIn('id', $old['moved_manuscript_ids'] ?? [])->where('work_id', $target->id)->update(['work_id' => $source->id]);

        // Columns the merge set go back, unless they were edited since (a merge of before backlog item 46 kept
        // the filled columns in target_before too)
        $after = $old['target_after'] ?? [];
        foreach ($old['target_before'] ?? [] as $column => $value) {
            if ($target->{$column} == ($after[$column] ?? null)) {
                $target->{$column} = $value;
            }
        }
        MergeComparison::undo($target, $old['fields'] ?? []);
        $target->save();
        $target->subjects()->detach($old['added_subject_ids'] ?? []);
        $target->languages()->detach($old['added_language_ids'] ?? []);
        DB::table('referrals')->whereIn('id', $old['referral_ids'] ?? [])->where('target_work_id', $target->id)->update(['target_work_id' => $source->id]);
        RecordRelations::undoMove($old['relations'] ?? [], $source->id);
        $this->undoAttachments($source, $target, $old);
        $this->undoRedirect('works', $source->id, $old['redirects'] ?? []);

        $this->recountWorks([$source->id, $target->id]);
        $this->recountPeople(DB::table('work_author')->whereIn('work_id', [$source->id, $target->id])->pluck('person_id')->all());
        $this->recountSubjects(DB::table('subject_work')->whereIn('work_id', [$source->id, $target->id])->pluck('subject_id')->all());
        $this->recountLanguages(DB::table('language_work')->whereIn('work_id', [$source->id, $target->id])->pluck('language_id')->all());
        Manuscript::where('work_id', $source->id)->searchable();
        $source->searchable();
        $target->searchable();

        return $source;
    }

    /**
     * What a merge of $source into $target would do, without doing it, for the admin's preview (backlog item 45):
     * the copies that move or fold into a copy of the same place. The fields are decided in the comparison
     * (MergeComparison::rows).
     *
     * @return array{moved: int, folded: int}
     */
    public function preview(Model $source, Model $target): array
    {
        if (! $source instanceof Work) {
            return ['moved' => 0, 'folded' => 0];
        }
        $keys = $target->manuscripts()->get()->map(fn (Manuscript $m) => $this->copyKey($m))->flip();
        $folded = $source->manuscripts()->get()->filter(fn (Manuscript $m) => $keys->has($this->copyKey($m)))->count();

        return ['moved' => $source->manuscripts()->count() - $folded, 'folded' => $folded];
    }

    /** Same library and shelfmark: the same physical copy (rule used by the addenda reconciliation) */
    private function copyKey(Manuscript $m): string
    {
        return \App\Helpers\TextNormalizer::normalize($m->city) . '|' . \App\Helpers\TextNormalizer::normalize($m->library) . '|'
            . preg_replace('/\s+/u', '', \App\Helpers\TextNormalizer::normalize($m->shelfmark));
    }

    /**
     * The duplicate's places in the sources and its still-open suggestions follow the record that is kept (step 13.3):
     * its entries in other sources, its addenda, and its own Fankha entry, which stays citable on the kept record as
     * an entry of its own. Its bibliography stays with it, since the kept record's list is its own.
     */
    private function moveAttachments($source, $target): array
    {
        $citations = $source->sourceCitations()->pluck('id')->all();
        $source->sourceCitations()->update(['citable_id' => $target->id]);
        $entries = SourceEntry::where('citable_type', $source::class)->where('citable_id', $source->id)
            ->where('role', '!=', SourceEntry::ROLE_CITATION)->get(['id', 'is_primary', 'legacy_type', 'legacy_id'])
            ->map(fn ($e) => $e->only(['id', 'is_primary', 'legacy_type', 'legacy_id']))->all();
        foreach ($entries as $e) {
            // the record and its catalogue references go with the duplicate; an addendum's row moves with it
            $keepsLegacy = $e['legacy_type'] === 'source_citation';
            SourceEntry::whereKey($e['id'])->update(['citable_id' => $target->id, 'is_primary' => false]
                + ($keepsLegacy ? [] : ['legacy_type' => null, 'legacy_id' => null]));
        }
        $suggestions = FieldSuggestion::where('suggestable_type', $source::class)->where('suggestable_id', $source->id)
            ->where('status', 'pending')->pluck('id')->all();
        FieldSuggestion::whereIn('id', $suggestions)->update(['suggestable_id' => $target->id]);

        return ['citation_ids' => $citations, 'moved_entries' => $entries, 'suggestion_ids' => $suggestions];
    }

    private function undoAttachments($source, $target, array $old): void
    {
        $target->sourceCitations()->whereIn('id', $old['citation_ids'] ?? [])->update(['citable_id' => $source->id]);
        // a merge of before step 13.3 cited the duplicate's entry in a source citation of its own
        if ($entry = $old['cited_entry_id'] ?? null) {
            $target->sourceCitations()->whereKey($entry)->delete();
            SourceEntry::where('legacy_type', 'source_citation')->where('legacy_id', $entry)->delete();
        }
        foreach ($old['moved_entries'] ?? [] as $e) {
            SourceEntry::whereKey($e['id'])->where('citable_id', $target->id)
                ->update(['citable_id' => $source->id] + array_intersect_key($e, array_flip(['is_primary', 'legacy_type', 'legacy_id'])));
        }
        FieldSuggestion::where('suggestable_type', $source::class)->where('suggestable_id', $target->id)
            ->whereIn('id', $old['suggestion_ids'] ?? [])->update(['suggestable_id' => $source->id]);
    }

    /** Records the redirect; returns the earlier redirects into $sourceId that now point at $targetId. */
    private function redirect(string $type, int $sourceId, int $targetId): array
    {
        $chained = EntityRedirect::where('entity_type', $type)->where('target_id', $sourceId)->pluck('source_id')->all();
        EntityRedirect::recordRedirect($type, $sourceId, $targetId);

        return $chained;
    }

    private function undoRedirect(string $type, int $sourceId, array $chained): void
    {
        EntityRedirect::removeRedirect($type, $sourceId);
        EntityRedirect::where('entity_type', $type)->whereIn('source_id', $chained)->update(['target_id' => $sourceId]);
    }

    /** Inserts a removed record back with its own id. */
    private function restore(string $class, string $table, array $attributes)
    {
        if ($class::find($attributes['id'])) {
            throw new InvalidArgumentException("رکوردی با شناسه‌ی #{$attributes['id']} هم‌اکنون وجود دارد.");
        }
        DB::table($table)->insert(array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $attributes));

        return $class::findOrFail($attributes['id']);
    }

    /** Empties the columns a merge filled, unless they were edited since. Leaves the record unsaved. */
    private function unfill($record, array $filled): void
    {
        foreach ($filled as $column => $value) {
            if ($record->{$column} == $value) {
                $record->{$column} = null;
            }
        }
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

    private function recountLanguages(array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            DB::table('languages')->where('id', $id)->update(['works_count' => DB::table('language_work')->where('language_id', $id)->count()]);
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
