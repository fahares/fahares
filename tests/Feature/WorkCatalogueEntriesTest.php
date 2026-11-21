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
    private function work(string $title, string $author, array $more = []): Work
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
}
