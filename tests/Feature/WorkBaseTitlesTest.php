<?php

namespace Tests\Feature;

use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A work made on another is indexed by its base's titles after its own (backlog item 95)
 */
class WorkBaseTitlesTest extends TestCase
{
    protected array $workIds = [];

    protected function tearDown(): void
    {
        DB::table('work_relations')->whereIn('work_id', $this->workIds)->delete();
        Work::whereIn('id', $this->workIds)->delete();

        parent::tearDown();
    }

    protected function work(array $attrs): Work
    {
        $work = Work::create($attrs + ['slug' => 'test-base-titles-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->workIds[] = $work->id;

        return $work;
    }

    public function test_a_commentary_title_is_indexed_with_its_base_not_among_the_own_titles()
    {
        $work = $this->work(['primary_title' => 'شرح نهج البلاغة', 'clean_title' => 'شرح نهج البلاغة', 'alternative_titles' => [['title' => 'الشرح الحدیدی']]]);
        $doc = $work->toSearchableArray();

        $this->assertNotContains('شرح نهج البلاغه', $doc['titles_names']);
        $this->assertContains('الشرح الحدیدی', $doc['titles_names']);
        $this->assertContains('شرح نهج البلاغه', $doc['base_titles']);
        $this->assertContains('نهج البلاغه', $doc['base_titles']);
        $this->assertArrayNotHasKey('derivative', $doc);
    }

    public function test_a_commentary_with_a_name_of_its_own_and_its_related_work()
    {
        $work = $this->work(['primary_title' => 'مصباح السالکین', 'clean_title' => 'مصباح السالکین',
            'alternative_titles' => [['title' => 'شرح نهج البلاغة']], 'related_work' => 'نهج البلاغة؛ شریف الرضی، محمد بن حسین (359- 406)']);
        $doc = $work->toSearchableArray();

        $this->assertSame(['مصباح السالکین', 'مصباح سالکین'], array_slice($doc['titles_names'], 0, 2));
        $this->assertSame(['نهج البلاغة'], $work->baseTitles());
    }

    public function test_a_translation_whose_clean_title_is_the_base()
    {
        $work = $this->work(['primary_title' => 'نهج البلاغة (ترجمه)', 'clean_title' => 'نهج البلاغة']);

        $this->assertNotContains('نهج البلاغه', $work->toSearchableArray()['titles_names']);
        $this->assertSame([[], ['نهج البلاغة (ترجمه)']], $work->searchTitles());
    }

    public function test_a_relation_in_work_relations_comes_before_the_text()
    {
        $base = $this->work(['primary_title' => 'تجرید الاعتقاد', 'clean_title' => 'تجرید الاعتقاد']);
        $work = $this->work(['primary_title' => 'شرح التجرید', 'clean_title' => 'شرح التجرید', 'related_work' => 'تجرید الکلام']);
        $this->assertSame(['التجرید', 'تجرید الکلام'], $work->baseTitles());

        DB::table('work_relations')->insert(['work_id' => $work->id, 'related_work_id' => $base->id, 'type' => 'commentary', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['تجرید الاعتقاد'], $work->fresh()->baseTitles());
    }

    public function test_an_epithet_naming_the_author_by_a_work_is_not_among_the_names()
    {
        $name = 'آزمایشی، شارح ' . uniqid();
        $person = \App\Models\Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'test-epithet-' . uniqid(), 'aliases' => ['ابن تجربه', "شارح نهج\u{200c}البلاغه"]]);
        $work = $this->work(['primary_title' => 'القصائد', 'clean_title' => 'القصائد', 'author_id' => $person->id, 'author_name' => $person->name]);
        $doc = $work->toSearchableArray();
        $person->delete();

        $this->assertContains('ابن تجربه', $doc['titles_names']);
        $this->assertNotContains('شارح نهج البلاغه', $doc['titles_names']);
        $this->assertStringContainsString('شارح نهج البلاغه', $doc['author_name']);
    }
}
