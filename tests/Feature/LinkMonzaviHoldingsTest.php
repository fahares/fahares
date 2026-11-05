<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Support\PageLinks\Holdings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sources:link-page-refs monzavi --only=holdings (step 14.34): a line of Monzavi's page naming a library's copy by its
 * catalogue page or its number links the copy of the entry's work that has them, or, for an entry tied to no work, the
 * copy of that number or page whose work's title is the entry's.
 */
class LinkMonzaviHoldingsTest extends TestCase
{
    private Source $source;

    private SourceVolume $volume;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = Source::create(['code' => 'monzavi', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 30, 'title' => 'فهرستواره کتاب‌های فارسی', 'short_name' => 'فهرستواره منزوی']);
        $this->volume = SourceVolume::create(['source_id' => $this->source->id, 'volume_number' => 10, 'facsimile_code' => 'monzavi-10']);
    }

    private function entry(string $title, string $text, ?Work $work = null): SourceItem
    {
        $item = SourceItem::create(['source_id' => $this->source->id, 'source_volume_id' => $this->volume->id, 'volume_number' => 10,
            'item_key' => (string) (15100 + SourceItem::count()), 'position' => SourceItem::count(), 'kind' => SourceItem::KIND_ENTRY,
            'title' => $title, 'title_normalized' => $title, 'page_start' => 151, 'page_end' => 151, 'text' => $text]);
        if ($work) {
            SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $this->source->id, 'source_item_id' => $item->id,
                'volume_number' => 10, 'page_start' => 151, 'role' => SourceEntry::ROLE_CITATION, 'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY]);
        }

        return $item;
    }

    public function test_the_lines_are_read_with_their_catalogue_page_and_number()
    {
        $text = "آستان قدس ش 9421/1، فهرست نشده، نوشته‌ی 1310 ق.\nدانشگاه 2705/12 ش (1) 3701، نوشته‌ی 1305 ق.\n"
            . "مجلس 448/19؛ نشریه 102/2.\nآستان قدس، الفبایی 34 بی‌نام نگارنده.\nنسخه‌ها 2632/4؛ ملک ش 5116/2.\nدانشگاهی 12/3.";
        $found = Holdings::in($text, ['آستان قدس', 'دانشگاه', 'مجلس', 'ملک']);
        $this->assertSame([
            ['آستان قدس ش 9421/1', null, null, '9421/1'],
            ['دانشگاه 2705/12 ش (1) 3701', 12, 2705, '3701'],
            ['مجلس 448/19', 19, 448, null],
            ['ملک ش 5116/2', null, null, '5116/2'],
        ], array_map(fn ($h) => [$h['span'], $h['volume'], $h['page'], $h['shelfmark']], $found));
        $this->assertSame('دانشگاه', mb_substr($text, $found[1]['offset'], 7));
        $this->assertSame(629, Holdings::base('629/1'));
    }

    public function test_a_holding_line_links_the_copy_of_the_entrys_work()
    {
        $library = fn (string $city, string $name) => Library::create(['city' => $city, 'name' => $name, 'slug' => 'l-' . uniqid()]);
        [$razavi, $tehran, $shiraz, $majlis] = [$library('مشهد', 'رضوی'), $library('تهران', 'دانشگاه'), $library('شیراز', 'شاهچراغ'), $library('تهران', 'مجلس')];
        $work = fn (string $title) => Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = fn (Library $lib, string $shelfmark, Work $w) => Manuscript::create(['work_id' => $w->id, 'library_id' => $lib->id, 'shelfmark' => $shelfmark, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1]);
        $cited = fn (Manuscript $m, int $volume, int $page) => SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $m->id, 'source_id' => $this->source->id,
            'volume_number' => $volume, 'page_start' => $page, 'origin' => SourceEntry::ORIGIN_FANKHA_CITATION, 'citation_text' => "[ف: {$volume}-{$page}]"]);

        $asrar = $work('اسرار الولایه');
        $other = $work('دیوان راز');
        $inRazavi = $copy($razavi, '9421', $asrar);
        $inTehran = $copy($tehran, '3701/1', $asrar);
        $cited($inTehran, 12, 2705);
        // a bound volume: of its parts, the one that is the entry's work
        $copy($shiraz, '629/2', $other);
        $inShiraz = $copy($shiraz, '629/1', $asrar);
        // by the catalogue's page alone
        $inMajlis = $copy($majlis, '8000', $asrar);
        $cited($inMajlis, 19, 448);
        // a number and a page that name two copies link neither
        $copy($tehran, '5000', $asrar);
        $cited($copy($tehran, '6000', $asrar), 3, 10);
        // an entry tied to no work: the copy of the number whose title is the entry's
        $divan = $copy($majlis, '1012', $work('دیوان حبیب'));
        $copy($majlis, '1013', $work('دیوان حبیب'));

        $first = "* اسرار الولایة.\nمثنوی است عرفانی.\nآستان قدس ش 9421/1، فهرست نشده.\nدانشگاه 2705/12 ش (1) 3701، نوشته‌ی 1305 ق.\n"
            . "شاهچراغ 320/2 ش (1) 629 (ردیف 270).\nمجلس 448/19.\nدانشگاه 10/3 ش 5000.\nملک ش 12.";
        $second = "* دیوان حبیب.\nمجلس ش 1012.";
        $this->entry('اسرار الولایة', $first, $asrar);
        $this->entry('دیوان حبیب', $second);
        $text = "{$first}\n\n{$second}";
        DB::table('source_pages')->insert(['source_volume_id' => $this->volume->id, 'page' => 151, 'text' => $text]);

        $this->artisan('sources:link-page-refs', ['source' => 'monzavi', '--only' => 'holdings'])->assertSuccessful();
        $links = DB::table('source_page_links')->where('kind', 'holding')->orderBy('offset')->get();
        $this->assertSame([
            ['آستان قدس ش 9421/1', $inRazavi->id, 'work+shelfmark'],
            ['دانشگاه 2705/12 ش (1) 3701', $inTehran->id, 'work+both'],
            ['شاهچراغ 320/2 ش (1) 629', $inShiraz->id, 'work+shelfmark'],
            ['مجلس 448/19', $inMajlis->id, 'work+page'],
            ['مجلس ش 1012', $divan->id, 'title+shelfmark'],
        ], $links->map(fn ($l) => [$l->span, $l->target_id, $l->method])->all());
        foreach ($links as $l) {
            $this->assertSame($l->span, mb_substr($text, $l->offset, mb_strlen($l->span)));
        }

        $html = $this->get('/sources/monzavi/10/page/151')->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . route('manuscripts.show', $inTehran->id) . '" id="r' . $links[1]->offset . '" class="book-sheet__mark book-sheet__mark--copy"', $html);

        // an editor's rejection stays on the next run
        DB::table('source_page_links')->where('target_id', $inMajlis->id)->update(['rejected_at' => now()]);
        $this->artisan('sources:link-page-refs', ['source' => 'monzavi', '--only' => 'holdings'])->assertSuccessful();
        $this->assertSame(5, DB::table('source_page_links')->where('kind', 'holding')->count());
        $this->assertSame(1, DB::table('source_page_links')->where('kind', 'holding')->whereNotNull('rejected_at')->count());

        // the copy's page lists where Monzavi names it
        $this->followingRedirects()->get(route('manuscripts.show', $inShiraz->id))->assertOk()->assertSee('یادکرد در منابع')
            ->assertSee('/sources/monzavi/10/page/151#r' . $links[2]->offset, false);
    }
}
