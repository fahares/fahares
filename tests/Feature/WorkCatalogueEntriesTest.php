<?php

namespace Tests\Feature;

use App\Models\Work;
use App\Support\WorkCatalogueEntries;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WorkCatalogueEntries (backlog item 6, step 14.285): a work's two texts of related works written as rows of
 * work_text_relations, each entry in its place and in the catalogue's own words; a second writing keeps the rows that
 * are still there with what they are tied to, adds the new and removes the gone; the rules tie a row to a work the
 * work is related to and untie it when the relation goes; the search reads the base titles from the rows.
 */
class WorkCatalogueEntriesTest extends TestCase
{
    private function work(string $title, ?string $author, array $more = []): Work
    {
        return $this->workWithTexts(['primary_title' => $title, 'clean_title' => $title, 'author_name' => $author, 'slug' => 'cat-' . uniqid(),
            'volume_number' => 1, 'page_start' => 1] + $more);
    }

    private function rows(Work $work): array
    {
        return DB::table('work_text_relations')->where('work_id', $work->id)->orderBy('side', 'desc')->orderBy('position')->get()
            ->map(fn ($r) => [$r->side, $r->position, $r->type, $r->text, $r->linked_work_id, $r->link_status, $r->link_source])->all();
    }

    public function test_the_two_texts_are_rows_in_the_catalogues_own_words()
    {
        // a note that begins with a letter sharing its first byte with «؛»: it is kept whole
        $note = 'انتخابی است از این دو کتاب که در ده باب، برای هر روز از دهۀ نخست ماه یک باب، مرتب شده و در پایان آن فهرستی از مطالب هر باب آمده است';
        $work = $this->work('منتخب دو کتاب', 'کسی، الف', [
            'related_work' => "الحشائش؛ دیوسکوریدس (سده 1م)؛ کتاب دوم = نام دیگر؛ کسی، ب (-800)؛ {$note}",
            'commentaries_and_glosses' => ['شرح منتخب؛ کسی، ج (-900)', '  ', 'منتخب دو کتاب (ترجمه)'],
        ]);
        $this->assertSame([
            ['related', 0, 'related', 'الحشائش؛ دیوسکوریدس (سده 1م)', null, null, null],
            ['related', 1, 'related', 'کتاب دوم = نام دیگر؛ کسی، ب (-800)', null, null, null],
            ['related', 999, 'note', $note, null, null, null],
            ['children', 0, 'commentary', 'شرح منتخب؛ کسی، ج (-900)', null, null, null],
            ['children', 2, 'translation', 'منتخب دو کتاب (ترجمه)', null, null, null],
        ], $this->rows($work));
        $this->assertSame(['الحشائش؛ دیوسکوریدس', 'کتاب دوم = نام دیگر؛ کسی، ب', null, 'شرح منتخب؛ کسی، ج', 'منتخب دو کتاب (ترجمه)'],
            DB::table('work_text_relations')->where('work_id', $work->id)->orderBy('side', 'desc')->orderBy('position')->pluck('entry_key')->all());
        // the search's base titles are the titles the rows of «وابسته به» name
        $this->assertSame(['دو کتاب', 'الحشائش', 'کتاب دوم', 'نام دیگر'], $work->baseTitles());
        // the page: both entries as text, and the note
        $page = WorkCatalogueEntries::page($work);
        $this->assertSame([[], 4, [$note]], [$page['printed'], count($page['unlinked']), $page['notes']]);

        // a relation ties the entry of the related work, by the rules; a row tied by a decision is left to it
        $second = $this->work('کتاب دوم', 'کسی، ب');
        $sharh = $this->work('شرحی به نامی دیگر', 'کسی، د');
        DB::table('work_relations')->insert(['work_id' => $work->id, 'related_work_id' => $second->id, 'type' => 'abridgment', 'source' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('work_text_relations')->where('work_id', $work->id)->where('text', 'شرح منتخب؛ کسی، ج (-900)')
            ->update(['linked_work_id' => $sharh->id, 'link_status' => 'linked', 'link_source' => 'model']);
        $this->assertSame(1, WorkCatalogueEntries::tie($work));
        $this->assertSame(0, WorkCatalogueEntries::tie($work));
        $this->assertSame([[$second->id, 'linked', 'auto'], [$sharh->id, 'linked', 'model']],
            DB::table('work_text_relations')->where('work_id', $work->id)->where('link_status', 'linked')->orderBy('side', 'desc')->get()
                ->map(fn ($r) => [$r->linked_work_id, $r->link_status, $r->link_source])->all());

        // the texts written again, one entry gone and one new: the rows still there keep their ids and their ties
        $ids = DB::table('work_text_relations')->where('work_id', $work->id)->whereNotNull('linked_work_id')->orderBy('id')->pluck('id')->all();
        $count = WorkCatalogueEntries::sync($work, "کتاب دوم = نام دیگر؛ کسی، ب (-800)؛ کتاب سوم؛ کسی، ه (-600)؛ {$note}", ['شرح منتخب؛ کسی، ج (-900)']);
        $this->assertSame(['added' => 1, 'removed' => 2, 'kept' => 3], $count);
        $this->assertSame([
            ['related', 0, 'related', 'کتاب دوم = نام دیگر؛ کسی، ب (-800)', $second->id, 'linked', 'auto'],
            ['related', 1, 'related', 'کتاب سوم؛ کسی، ه (-600)', null, null, null],
            ['related', 999, 'note', $note, null, null, null],
            ['children', 0, 'commentary', 'شرح منتخب؛ کسی، ج (-900)', $sharh->id, 'linked', 'model'],
        ], $this->rows($work));
        $this->assertSame($ids, DB::table('work_text_relations')->where('work_id', $work->id)->whereNotNull('linked_work_id')->orderBy('id')->pluck('id')->all());
        // one side left as it is
        WorkCatalogueEntries::sync($work, false, []);
        $this->assertCount(3, $this->rows($work));
        $this->assertTrue(WorkCatalogueEntries::relatedIs($work, "کتاب دوم = نام دیگر؛ کسی، ب (-800)؛ کتاب سوم؛ کسی، ه (-600)؛ {$note}"));
        $this->assertFalse(WorkCatalogueEntries::relatedIs($work, 'کتاب دوم = نام دیگر؛ کسی، ب (-800)'));

        // the relation gone, the rules untie the row
        DB::table('work_relations')->delete();
        $this->assertSame(1, WorkCatalogueEntries::tie($work));
        $this->assertNull(DB::table('work_text_relations')->where('work_id', $work->id)->where('position', 0)->value('link_status'));
    }

    /** Step 14.296: several related works of one title; the entry's author says which, and an entry with no author is a work with none */
    public function test_entries_of_one_title_are_tied_by_their_author()
    {
        $base = $this->work('الکتاب', 'کسی، الف', ['commentaries_and_glosses' => [
            'شرح الکتاب؛ کسی، ب (-900)', 'شرح الکتاب', 'شرح الکتاب؛ کسی، ناشناس (-950)', 'شرح الکتاب', 'شرح الکتاب', 'حاشیة الکتاب (2عنوان)',
            'شرح الکتاب؛ کسی، ج', 'حاشیة الکتاب؛ ناشناس', 'شرح الکتاب کسی، ه (-800)', 'حاشیة الکتاب؛ ناشناس (3 عنوان مختلف)',
        ]]);
        $by = $this->work('شرح الکتاب', 'کسی، ب');
        $other = $this->work('شرح الکتاب', 'کسی، ج');
        $glued = $this->work('شرح الکتاب', 'کسی، ه');
        $anon = [$this->work('شرح الکتاب', null), $this->work('شرح الکتاب', null)];
        $glosses = [$this->work('حاشیة الکتاب', null), $this->work('حاشیة الکتاب', null)];
        foreach ([$by, $other, $glued, ...$anon, ...$glosses] as $w) {
            DB::table('work_relations')->insert(['work_id' => $w->id, 'related_work_id' => $base->id, 'type' => 'commentary', 'source' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame(6, WorkCatalogueEntries::tie($base));
        $this->assertSame(0, WorkCatalogueEntries::tie($base));
        // the named author's; the two works with no author, in order, for the first two entries with none; the third
        // such entry, the entry of an author no work has, and the entry that stands for two works are tied to none;
        // an author written with no life dates; «ناشناس» for no author; the author after the title with no «؛»; and
        // «ناشناس» that stands for three works
        $this->assertSame([$by->id, $anon[0]->id, null, $anon[1]->id, null, null, $other->id, $glosses[0]->id, $glued->id, null],
            DB::table('work_text_relations')->where('work_id', $base->id)->orderBy('position')->pluck('linked_work_id')->all());

        // step 14.345: an entry that stands for several works is no unlinked work: the page gives a search of its
        // title, with no mark, author or count
        $this->assertSame(['حاشیة الکتاب', 'حاشیة الکتاب'], array_column(WorkCatalogueEntries::page($base)['unlinked'], 'search'));
        $this->assertSame('آداب المریدین', WorkCatalogueEntries::searchTitle('آداب المریدین (ترجمه) (سه ترجمه ناشناس)'));
        $this->assertSame('شرح آداب البحث', WorkCatalogueEntries::searchTitle('شرح آداب البحث = شرح دیگر؛ ناشناس به همراه 28 شرح ناشناس دیگر رک: دنا 395/6 (29 شرح ناشناس)'));
        $this->assertFalse(WorkCatalogueEntries::several('شرح الکتاب (ترجمه)؛ کسی، ب (-900)'));
        $html = $this->followingRedirects()->get(route('works.show', $base))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'جستجوی این عنوان'));
        $this->assertStringContainsString(e(route('search', ['type' => 'works', 'scope' => 'titles', 'q' => 'حاشیة الکتاب'])), $html);
        // the two entries of an author no work has are the unlinked works still
        $this->assertSame(2, substr_count($html, 'بی پیوند به رکورد اثر'));
    }

    /** Step 14.337: a row of «وابسته به» that is only a name is the base text's author, tied to the person, and no work */
    public function test_a_row_that_is_only_the_base_texts_author_is_tied_to_the_person()
    {
        $person = fn (string $name, array $more = []) => \App\Models\Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'cat-p-' . uniqid(), 'is_author' => true] + $more);
        $imam = $person('علی بن حسین (ع)، امام چهارم', ['death_year_hijri' => 94]);
        $kashi = $person('کشی، ابراهیم');
        $person('نامی، دوگانه', ['death_year_hijri' => 500]);
        $late = $person('نامی، دوگانه', ['death_year_hijri' => 700]);
        $row = fn (Work $w) => (array) DB::table('work_text_relations')->where('work_id', $w->id)->first(['type', 'linked_person_id', 'text']);

        // a name with its life dates, of one person; with no dates, of one person; of two people, told by the year
        $sharh = $this->work('شرح صحیفه', 'کسی، الف', ['related_work' => 'علی بن حسین (ع)، امام چهارم (38-94ق)']);
        $this->assertSame(['type' => 'author', 'linked_person_id' => $imam->id, 'text' => 'علی بن حسین (ع)، امام چهارم (38-94ق)'], $row($sharh));
        $this->assertSame($kashi->id, $row($this->work('شرح وسائل', 'کسی، ب', ['related_work' => 'کشی، ابراهیم']))['linked_person_id']);
        $this->assertSame($late->id, $row($this->work('شرح دیگر', 'کسی، ج', ['related_work' => 'منسوب به نامی، دوگانه (-700)']))['linked_person_id']);
        // two people of the name and no year to tell them: the author still, tied to none
        $this->assertSame(['author', null], array_slice(array_values($row($this->work('شرح سوم', 'کسی، د', ['related_work' => 'نامی، دوگانه (-600)']))), 0, 2));
        // a name no person has is an entry as before: it may be a title with its author written after it
        $this->assertSame('related', $row($this->work('شرح چهارم', 'کسی، ه', ['related_work' => 'آب حیات ناشناخته، فلان بن بهمان (-300)']))['type']);
        $this->assertSame('related', $row($this->work('شرح ششم', 'کسی، ز', ['related_work' => 'ناشناخته، فلان']))['type']);
        // a title and its author are an entry as before
        $this->assertSame('related', $row($this->work('شرح پنجم', 'کسی، و', ['related_work' => 'الصحیفة؛ علی بن حسین (ع)، امام چهارم (38-94ق)']))['type']);

        // the page: the author under his own label and linked, and no «unlinked» work; the search reads no title from it
        $page = WorkCatalogueEntries::page($sharh);
        $this->assertSame([[], [['text' => 'علی بن حسین (ع)، امام چهارم (38-94ق)', 'person' => $imam->id]]], [$page['unlinked'], $page['authors']]);
        $this->assertNotContains('علی بن حسین (ع)، امام چهارم', $sharh->baseTitles());
        $this->followingRedirects()->get(route('works.show', $sharh))->assertOk()->assertSee('پدیدآور متن اصلی')
            ->assertSee('<a href="' . route('people.show', $imam) . '"', false)->assertDontSee('بی پیوند به رکورد اثر');
        // the person gone, no person has the name: the row is an entry again
        $imam->delete();
        $this->assertSame(1, WorkCatalogueEntries::tie($sharh));
        $this->assertSame(['related', null], array_slice(array_values($row($sharh)), 0, 2));
    }
}
