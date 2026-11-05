<?php

namespace Tests\Feature;

use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Support\CatalogCitations;
use Tests\TestCase;

/** One source entry for each place of each source a copy's Fankha text cites (step 14.42). */
class OtherCitationsTest extends TestCase
{
    protected Library $library;

    protected CatalogVolume $own;

    protected CatalogVolume $gift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'آستان قدس', 'city' => 'مشهد', 'slug' => 'astan-' . uniqid()]);
        $this->own = CatalogVolume::create(['title' => 'فهرست کتب خطی کتابخانه آستان قدس - جلد بیست و یکم', 'volume_number' => 21, 'library_id' => $this->library->id]);
        $this->gift = CatalogVolume::create(['title' => 'فهرست نسخه‌های اهدایی - جلد سوم', 'volume_number' => 3, 'library_id' => $this->library->id]);
    }

    protected function copy(string $citation, CatalogVolume $volume): Manuscript
    {
        $work = Work::create(['primary_title' => 'رساله', 'clean_title' => 'رساله', 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create(['work_id' => $work->id, 'library_id' => $this->library->id, 'shelfmark' => (string) random_int(1, 99999),
            'catalog_volume_id' => $volume->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1,
            'raw_text' => "نسخه، 21گ، 15 سطر، اندازه: 10×16سم {$citation}"]);
    }

    protected function others(Manuscript $m)
    {
        return SourceEntry::where('legacy_type', 'fankha_citation_more')->where('legacy_id', $m->id)->orderBy('legacy_index')->get();
    }

    public function test_a_bracket_is_parted_into_its_places_of_each_source()
    {
        $texts = fn ($b) => array_column(CatalogCitations::places($b), 'text');
        $this->assertSame(['فیلمها ف: 1 - 566', 'فیلمها ف: 1-743'], $texts('فیلمها ف: 1 - 566 و 743'));
        $this->assertSame(['ف: 11-495', 'ف: 12-407'], $texts('ف: 11-495 و 12-407'));
        $this->assertSame(['ف: 2 - 444', 'عکسی ف: 5 - 350'], $texts('ف: 2 - 444 و عکسی ف: 5 - 350'));
        $this->assertSame(['ف: 1-196', 'ف: 3-263', 'ف: 4-21'], $texts('ف: 1-196؛ 3-263؛ 4-21'));
        $this->assertSame(['ف: 2-236', 'ف: 3-147'], $texts('ف: 2-236، ف: 3-147'));
        $this->assertSame([137, 155], array_column(CatalogCitations::places('ف: 137 و 155'), 'page'));
        // «و» inside a name or between the issues of a journal parts nothing
        $this->assertSame(['رشت و همدان: ف: -1398'], $texts('رشت و همدان: ف: -1398'));
        $this->assertSame(['تراثنا: س 13ش 2 و 3-329'], $texts('تراثنا: س 13ش 2 و 3-329'));
        $this->assertSame(['تراثنا: س18ش1و2 - 213'], $texts('تراثنا: س18ش1و2 - 213'));
        $this->assertSame(['اندیشه حوزه: س 2، ش 7-214'], $texts('اندیشه حوزه: س 2، ش 7-214'));
    }

    public function test_the_other_places_are_entries_at_the_volume_the_library_cites_them_at()
    {
        $this->copy('[اهدائی رهبر: 3-650]', $this->gift);
        $this->copy('[ف: 21-1000]', $this->own);
        $both = $this->copy('[اهدائی رهبر: 3-673] و [ف: 21-1380]', $this->own);

        $others = $this->others($both);
        $this->assertCount(1, $others);
        $this->assertSame('اهدائی رهبر: 3-673', $others[0]->citation_text);
        $this->assertSame($this->gift->id, $others[0]->source_volume_id);
        $this->assertSame(673, $others[0]->page_start);
        $this->assertSame(SourceEntry::ORIGIN_FANKHA_CITATION, $others[0]->origin);

        // the entries follow the text
        $both->update(['raw_text' => 'نسخه [ف: 21-1380]']);
        $this->assertCount(0, $this->others($both));
    }

    public function test_two_pages_of_a_volume_are_two_entries_under_one_heading()
    {
        $copy = $this->copy('[ف: 21-566 و 743]', $this->own);

        $this->assertSame(['ف: 21-743'], $this->others($copy)->pluck('citation_text')->all());
        $this->assertSame($this->own->id, $this->others($copy)[0]->source_volume_id);
        $this->get(route('manuscripts.show', $copy))->assertOk()
            ->assertSee('فهرست کتب خطی کتابخانه آستان قدس - جلد بیست و یکم، ج ۲۱، ص ۵۶۶ و ۷۴۳');
    }
}
