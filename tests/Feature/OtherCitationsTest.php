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
    public function test_a_rebuilt_copy_whose_citation_changed_goes_to_the_volume_its_library_cites()
    {
        $this->copy('[ف: 21-1000]', $this->own);
        $wrong = $this->copy('[اهدائی رهبر: 3-650]', $this->gift);
        $meta = $wrong->fresh()->metadata ?? [];
        $file = tempnam(sys_get_temp_dir(), 'rebuild');
        file_put_contents($file, json_encode(['records' => [[
            'table' => 'manuscripts', 'id' => $wrong->id, 'shelfmark' => $wrong->shelfmark, 'category' => 'rebuild', 'v2' => 'v1-1#0',
            'changes' => [
                'raw_text' => ['from' => $wrong->raw_text, 'to' => 'نسخه، 9گ [ف: 21-1200]'],
                'metadata' => ['from' => $meta ?: null, 'to' => ['catalog_citation' => '[ف: 21-1200]']],
            ],
        ]]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:rebuild-copies', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('1 copies relinked')
            ->assertSuccessful();
        @unlink($file);

        $this->assertSame($this->own->id, (int) $wrong->fresh()->catalog_volume_id);
        $this->assertSame($this->own->id, SourceEntry::where('legacy_type', 'fankha_citation')->where('legacy_id', $wrong->id)->value('source_volume_id'));
    }

    /** Step 14.88: «اهدائی رهبر: 660-3» is page 660 of volume 3, taken only where that page lies in the volume's pages. */
    public function test_a_place_written_page_first_goes_to_its_volume_when_the_page_fits()
    {
        $this->copy('[اهدائی رهبر: 3-650]', $this->gift);
        $this->copy('[اهدائی رهبر: 3-690]', $this->gift);
        $this->copy('[ف: 21-1000]', $this->own);
        $reversed = $this->copy('[اهدائی رهبر: 660-3] و [ف: 21-1380]', $this->own);
        $outside = $this->copy('[اهدائی رهبر: 900-3] و [ف: 21-1381]', $this->own);

        $entry = $this->others($reversed)[0];
        $this->assertSame($this->gift->id, $entry->source_volume_id);
        $this->assertSame([3, 660], [$entry->volume_number, $entry->page_start]);
        $this->assertNull($this->others($outside)[0]->source_volume_id);
    }

    public function test_a_volume_in_parts_is_told_by_the_part_its_citation_names()
    {
        // step 14.105: «جلد دوم: اخبار» in two parts; one copy of part 1 cited at a page of part 2 spoils the page ranges
        $first = CatalogVolume::create(['title' => 'فهرست نسخه‌های اهدایی - جلد دوم: اخبار ۱', 'volume_number' => 2, 'library_id' => $this->library->id]);
        $second = CatalogVolume::create(['title' => 'فهرست نسخه‌های اهدایی - جلد دوم: اخبار ۲', 'volume_number' => 2, 'library_id' => $this->library->id]);
        foreach ([18, 200, 532] as $page) {
            $this->copy("[اهدائی رهبر: 2 اخبار 1 - {$page}]", $first);
        }
        foreach ([488, 815] as $page) {
            $this->copy("[اهدائی رهبر: 2 اخبار2-{$page}]", $second);
        }
        $this->copy('[ف: 21-1000]', $this->own);
        $copy = $this->copy('[اهدائی رهبر: 2 اخبار 2 - 492] و [ف: 21-1380]', $this->own);

        $this->assertSame(2, \App\Sources\SourceEntrySync::partOf('اهدائی رهبر: 2 اخبار 2 - 492'));
        $this->assertNull(\App\Sources\SourceEntrySync::partOf('اهدائی رهبر: 1علوم قرآن-15'));
        $this->assertSame($second->id, $this->others($copy)[0]->source_volume_id);
    }

    public function test_a_catalogue_only_another_library_cites_is_found_there()
    {
        // step 14.105: a copy of Marashi once in Ostadi's library cites the catalogue of that library
        $ostadi = Library::create(['name' => 'استادی، رضا', 'city' => 'قم', 'slug' => 'ostadi-' . uniqid()]);
        $catalogue = CatalogVolume::create(['title' => 'یکصد و شصت نسخه از یک کتابخانه شخصی', 'volume_number' => 1, 'library_id' => $ostadi->id]);
        $own = $this->copy('[صد و شصت نسخه: 60]', $catalogue);
        $own->update(['library_id' => $ostadi->id]);
        $this->copy('[ف: 21-1000]', $this->own);
        $copy = $this->copy('تملک: رضا استادی [صد و شصت نسخه: - 11] و [ف: 21-1380]', $this->own);
        $unnamed = $this->copy('[صد و شصت نسخه: - 12] و [ف: 21-1382]', $this->own);
        $generic = $this->copy('[ف: 99-5] و [ف: 21-1381]', $this->own);

        $this->assertSame($catalogue->id, $this->others($copy)[0]->source_volume_id);
        // without the library's name in its text the copy keeps the place without a volume
        $this->assertNull($this->others($unnamed)[0]->source_volume_id);
        $this->assertNull($this->others($generic)[0]->source_volume_id);
    }
}
