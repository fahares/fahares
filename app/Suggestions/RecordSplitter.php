<?php

namespace App\Suggestions;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Applies the relational suggestion «conflated»: two works (or two people) were catalogued as one
 * record. The reviewer creates the second record and picks what belongs to it: manuscripts of a
 * work; works, scribed manuscripts and other roles of a person. The split returns what it moved
 * (kept in the audit log, action "split") and undo() moves it back and deletes the new record.
 */
class RecordSplitter
{
    /**
     * @param  array{title: string, author: string, author_id?: ?int, copy_subjects?: bool}  $new
     * @param  int[]  $manuscriptIds
     */
    public function splitWork(Work $work, array $new, array $manuscriptIds): array
    {
        $title = trim($new['title'] ?? '');
        if ($title === '') {
            throw new InvalidArgumentException('عنوان اثر تازه را بنویسید.');
        }
        $ids = Manuscript::where('work_id', $work->id)->whereIn('id', $manuscriptIds)->pluck('id')->all();
        if (! $ids) {
            throw new InvalidArgumentException('دست‌کم یک نسخه را برای انتقال به اثر تازه انتخاب کنید.');
        }

        [$authorId, $authorName] = match ($new['author'] ?? 'same') {
            'same' => [$work->author_id, $work->author_name],
            'person' => ($p = Person::find($new['author_id'] ?? 0)) ? [$p->id, $p->name] : throw new InvalidArgumentException('شخصی با این شناسه پیدا نشد.'),
            default => [null, null],
        };
        $created = Work::create([
            'primary_title' => $title,
            'clean_title' => $title,
            'slug' => 'work-' . Str::random(12),
            'author_id' => $authorId,
            'author_name' => $authorName,
            'catalog_id' => $work->catalog_id,
            'volume_number' => $work->volume_number,
            'page_start' => $work->page_start,
            'page_end' => $work->page_end,
        ]);
        $created->update(['slug' => mb_substr(Str::slug($title, '-', null) ?: 'work', 0, 180) . '-' . $created->id]);
        if (! empty($new['copy_subjects'])) {
            $created->subjects()->sync($work->subjects()->pluck('subjects.id')->all());
            $created->languages()->sync($work->languages()->pluck('languages.id')->all());
        }

        Manuscript::whereIn('id', $ids)->update(['work_id' => $created->id]);
        $this->recountWorks([$work->id, $created->id]);
        $this->recountTaxonomies($created);
        Person::find($authorId)?->recountContributions();
        Manuscript::whereIn('id', $ids)->searchable();
        Work::whereKey([$work->id, $created->id])->searchable();

        return ['created_id' => $created->id, 'manuscript_ids' => $ids];
    }

    /**
     * @param  array{name: string, death_year_hijri?: ?int}  $new
     * @param  array{works?: int[], manuscripts?: int[], roles?: int[]}  $picked
     */
    public function splitPerson(Person $person, array $new, array $picked): array
    {
        $name = trim($new['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('نام شخص تازه را بنویسید.');
        }
        $works = DB::table('work_author')->where('person_id', $person->id)->whereIn('work_id', $picked['works'] ?? [])->pluck('work_id')->all();
        $manuscripts = DB::table('manuscript_scribe')->where('person_id', $person->id)->whereIn('manuscript_id', $picked['manuscripts'] ?? [])->pluck('manuscript_id')->all();
        $roles = DB::table('manuscript_people')->where('person_id', $person->id)->whereIn('id', $picked['roles'] ?? [])->pluck('id')->all();
        if (! $works && ! $manuscripts && ! $roles) {
            throw new InvalidArgumentException('دست‌کم یک اثر یا نسخه را برای انتقال به شخص تازه انتخاب کنید.');
        }

        $created = Person::create([
            'name' => $name,
            'normalized_name' => mb_substr(TextNormalizer::normalize($name), 0, 600),
            'slug' => 'person-' . Str::random(12),
            'death_year_hijri' => $new['death_year_hijri'] ?? null,
        ]);
        $created->update(['slug' => (Str::slug($name, '-', null) ?: 'person') . '-' . $created->id]);

        // Pivot rows and primary columns move together (bulk updates bypass SyncsPrimaryContributor)
        DB::table('work_author')->where('person_id', $person->id)->whereIn('work_id', $works)->update(['person_id' => $created->id]);
        $primaryWorks = Work::whereIn('id', $works)->where('author_id', $person->id)->pluck('id')->all();
        Work::whereIn('id', $primaryWorks)->update(['author_id' => $created->id]);
        DB::table('manuscript_scribe')->where('person_id', $person->id)->whereIn('manuscript_id', $manuscripts)->update(['person_id' => $created->id]);
        $primaryManuscripts = Manuscript::whereIn('id', $manuscripts)->where('scribe_id', $person->id)->pluck('id')->all();
        Manuscript::whereIn('id', $primaryManuscripts)->update(['scribe_id' => $created->id]);
        DB::table('manuscript_people')->whereIn('id', $roles)->update(['person_id' => $created->id]);

        $created->update(['is_author' => (bool) $works, 'is_scribe' => (bool) $manuscripts]);
        $person->recountContributions();
        $created->recountContributions();
        Work::whereIn('id', $works)->searchable();
        Manuscript::whereIn('id', $manuscripts)->searchable();
        $person->searchable();
        $created->searchable();

        return [
            'created_id' => $created->id,
            'work_ids' => $works, 'primary_work_ids' => $primaryWorks,
            'manuscript_ids' => $manuscripts, 'primary_manuscript_ids' => $primaryManuscripts,
            'role_ids' => $roles,
        ];
    }

    /** Moves back what a split moved and deletes the record it created. */
    public function undo(string $class, int $sourceId, array $old): void
    {
        $createdId = $old['created_id'] ?? throw new InvalidArgumentException('اطلاعات تفکیک در این لاگ ثبت نشده است.');
        $source = $class::find($sourceId) ?? throw new InvalidArgumentException("رکورد اصلی (#{$sourceId}) دیگر وجود ندارد.");
        $created = $class::find($createdId) ?? throw new InvalidArgumentException("رکورد تازه (#{$createdId}) دیگر وجود ندارد.");

        if ($class === Work::class) {
            if (Manuscript::where('work_id', $createdId)->whereNotIn('id', $old['manuscript_ids'])->exists()) {
                throw new InvalidArgumentException("پس از تفکیک، نسخه‌های دیگری هم به اثر #{$createdId} افزوده شده است. نخست آن‌ها را منتقل کنید.");
            }
            Manuscript::whereIn('id', $old['manuscript_ids'])->where('work_id', $createdId)->update(['work_id' => $sourceId]);
            $authorId = $created->author_id;
            $created->delete();
            // the entries saving the new work made (its Fankha place) go with it
            \App\Models\SourceEntry::where('citable_type', Work::class)->where('citable_id', $createdId)->delete();
            $this->recountWorks([$sourceId]);
            $this->recountTaxonomies($source);
            Person::find($authorId)?->recountContributions();
            Manuscript::whereIn('id', $old['manuscript_ids'])->searchable();
            $source->searchable();

            return;
        }

        DB::table('work_author')->where('person_id', $createdId)->whereIn('work_id', $old['work_ids'])->update(['person_id' => $sourceId]);
        Work::whereIn('id', $old['primary_work_ids'])->where('author_id', $createdId)->update(['author_id' => $sourceId]);
        DB::table('manuscript_scribe')->where('person_id', $createdId)->whereIn('manuscript_id', $old['manuscript_ids'])->update(['person_id' => $sourceId]);
        Manuscript::whereIn('id', $old['primary_manuscript_ids'])->where('scribe_id', $createdId)->update(['scribe_id' => $sourceId]);
        DB::table('manuscript_people')->whereIn('id', $old['role_ids'])->where('person_id', $createdId)->update(['person_id' => $sourceId]);
        if ($blockers = app(RecordRemover::class)->blockers($created)) {
            throw new InvalidArgumentException("پس از تفکیک، پیوندهای دیگری هم به شخص #{$createdId} افزوده شده است. نخست آن‌ها را منتقل کنید.");
        }
        $created->delete();
        $source->recountContributions();
        Work::whereIn('id', $old['work_ids'])->searchable();
        Manuscript::whereIn('id', $old['manuscript_ids'])->searchable();
        $source->searchable();
    }

    private function recountWorks(array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            Work::whereKey($id)->update(['manuscripts_count' => Manuscript::where('work_id', $id)->count()]);
        }
    }

    private function recountTaxonomies(Work $work): void
    {
        foreach (DB::table('subject_work')->where('work_id', $work->id)->pluck('subject_id') as $id) {
            DB::table('subjects')->where('id', $id)->update(['works_count' => DB::table('subject_work')->where('subject_id', $id)->count()]);
        }
        foreach (DB::table('language_work')->where('work_id', $work->id)->pluck('language_id') as $id) {
            DB::table('languages')->where('id', $id)->update(['works_count' => DB::table('language_work')->where('language_id', $id)->count()]);
        }
    }
}
