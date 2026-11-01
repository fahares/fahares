<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sources:link-dharia-titles (step 13.128): the titles Noor tags in al-Dhari'a's text linked to the entry of that
 * title, and the page showing them.
 */
class LinkDhariaTitlesTest extends TestCase
{
    public function test_a_tagged_title_links_the_one_entry_of_that_title_and_the_page_shows_it()
    {
        $source = Source::create(['code' => 'dharia', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'الذریعة']);
        $entry = function (int $volume, int $page, string $title) use ($source) {
            $v = SourceVolume::firstOrCreate(['source_id' => $source->id, 'volume_number' => $volume], ['facsimile_code' => "dharia-{$volume}"]);

            return SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $v->id, 'volume_number' => $volume,
                'item_key' => (string) ($page * 10), 'position' => $page, 'kind' => SourceItem::KIND_ENTRY, 'title' => $title,
                'title_normalized' => $title, 'page_start' => $page, 'page_end' => $page, 'text' => "( {$title} ) …"]);
        };
        $salaf = $entry(4, 10, 'تجارب السلف');
        $irshad13 = $entry(13, 5, 'شرح الإرشاد');
        $irshad14 = $entry(14, 7, 'شرح الإرشاد');
        $diwan = $entry(9, 909, 'ديوان قطب الدين الشيرازي');
        $here = $entry(4, 100, 'أنيس العقلاء');
        $other = $entry(4, 101, 'أنيس العقلاء الكبير');

        $m = fn (string $kind, string $text) => "\u{E020}{$kind}\u{E021}{$text}\u{E022}";
        $text = '( أنيس العقلاء ) ' . $m('SB', 'للشيخ فلان') . ' مر بعنوان ' . $m('VB', 'تجارب السلف') . ' . و له '
            . $m('TB', 'بتجارب السلف') . ' و ' . $m('WB', 'شرح الإرشاد') . ' و ' . $m('WB', 'شرح الإرشاد') . ' في (7:14) و '
            . $m('EB', 'تجارب السلف') . ' في (11:4) و ' . $m('WB', 'الرسالة') . ' و ' . $m('EB', 'أنيس العقلاء') . '.';
        $page4 = DB::table('source_pages')->insertGetId(['source_volume_id' => $salaf->source_volume_id, 'page' => 100, 'text' => $text]);
        $page9 = DB::table('source_pages')->insertGetId(['source_volume_id' => $diwan->source_volume_id, 'page' => 1003,
            'text' => 'راجع ' . $m('VB', 'قطب الدين') . ' في ص 909. و ' . $m('VB', 'قطب الدين') . ' في ص 500.']);

        $this->artisan('sources:link-dharia-titles')->assertSuccessful();
        $links = DB::table('source_page_links')->orderBy('source_page_id')->orderBy('ordinal')->get()
            ->map(fn ($l) => [$l->source_page_id, $l->ordinal, $l->kind, $l->target_id, $l->method])->all();
        $this->assertSame([
            // the title as it is, and with the Arabic «ب»
            [$page4, 1, 'VB', $salaf->id, 'title'],
            [$page4, 2, 'TB', $salaf->id, 'title-prefix'],
            // of two entries of the title, the one the reference after it gives; without one, none
            [$page4, 4, 'WB', $irshad14->id, 'title+ref'],
            // a poet's name and the page of his «ديوان …»
            [$page9, 0, 'VB', $diwan->id, 'ref+part'],
        ], $links);
        // not linked: a reference to another page than the one entry's (ordinal 5), a common title (6), the entry
        // itself (7), a page with no entry of that name (page 9, ordinal 1)

        // an editor's rejection stays when the links are made again
        DB::table('source_page_links')->where('source_page_id', $page4)->where('ordinal', 2)->update(['rejected_at' => now()]);
        $this->artisan('sources:link-dharia-titles')->assertSuccessful();
        $this->assertSame(4, DB::table('source_page_links')->count());
        $this->assertNotNull(DB::table('source_page_links')->where('source_page_id', $page4)->where('ordinal', 2)->value('rejected_at'));

        $html = $this->get('/sources/dharia/4/page/100')->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . $salaf->url() . '" class="book-sheet__mark book-sheet__mark--entry" title="الذریعة، '
            . $salaf->placeLabel() . ': تجارب السلف (پیوند ماشینی)" data-noor="VB" data-record-link="entry">تجارب السلف</a>', $html);
        $this->assertStringContainsString('href="' . $irshad14->url() . '"', $html);
        $this->assertStringNotContainsString('href="' . $irshad13->url() . '"', $html);
        // the rejected link and the unlinked marks stay marks
        $this->assertStringContainsString('<span data-noor="TB">بتجارب السلف</span>', $html);
        $this->assertStringContainsString('<span data-noor="WB">الرسالة</span>', $html);
        $this->assertStringNotContainsString('href="' . $other->url() . '"', $html);
    }
}
