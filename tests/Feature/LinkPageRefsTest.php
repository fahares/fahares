<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourcePage;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Support\BibliographyLinks;
use App\Support\PageLinks\Titles;
use App\Support\PageMarks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sources:link-page-refs (step 13.155): a shelfmark after a library's mark linked to the copy when its work is the
 * entry's, and a reference to a volume and page of the source itself linked to the entry it names there, or the page;
 * the page showing them, and the copy's page its mentions.
 */
class LinkPageRefsTest extends TestCase
{
    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = Source::create(['code' => 'dharia', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'الذریعة', 'short_name' => 'الذریعة']);
    }

    /** A page of the source with its text, and an entry starting on it for each paragraph whose title is given. */
    private function page(int $volume, int $page, string $text = '', array $titles = []): int
    {
        $v = SourceVolume::firstOrCreate(['source_id' => $this->source->id, 'volume_number' => $volume], ['facsimile_code' => "dharia-{$volume}"]);
        foreach ($titles as $n => $title) {
            $this->entry($volume, $page, $title, $title === null ? '' : collect(explode("\n\n", PageMarks::strip($text)))->first(fn ($p) => str_starts_with($p, "( {$title} )")));
        }

        return DB::table('source_pages')->insertGetId(['source_volume_id' => $v->id, 'page' => $page, 'text' => $text]);
    }

    private function entry(int $volume, int $page, string $title, ?string $text = null): SourceItem
    {
        $v = SourceVolume::firstOrCreate(['source_id' => $this->source->id, 'volume_number' => $volume], ['facsimile_code' => "dharia-{$volume}"]);

        return SourceItem::create(['source_id' => $this->source->id, 'source_volume_id' => $v->id, 'volume_number' => $volume,
            'item_key' => (string) ($page * 100 + SourceItem::count()), 'position' => $page, 'kind' => SourceItem::KIND_ENTRY, 'title' => $title,
            'title_normalized' => $title, 'page_start' => $page, 'page_end' => $page, 'text' => $text ?: "( {$title} ) …"]);
    }

    public function test_a_shelfmark_after_a_library_links_the_copy_of_the_entrys_work()
    {
        $library = fn (string $name) => Library::create(['city' => 'تهران', 'name' => $name, 'slug' => 'l-' . uniqid()]);
        [$majlis, $ilahiyat, $sepahsalar, $malek] = [$library('مجلس'), $library('الهیات'), $library('سپهسالار'), $library('ملک')];
        $work = fn (string $title) => Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = fn (Library $lib, string $shelfmark, Work $w) => Manuscript::create(['work_id' => $w->id, 'library_id' => $lib->id, 'shelfmark' => $shelfmark, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1]);
        $khulasa = $work('خلاصة الاحکام');
        $inMajlis = $copy($majlis, '1012', $khulasa);
        $inIlahiyat = $copy($ilahiyat, '799 د', $khulasa);
        $copy($ilahiyat, '799 ب', $work('تجوید قرآن'));
        // an old number, now another work's
        $copy($sepahsalar, '404', $work('دیوان حافظ'));
        // a bound volume: of its parts, the one that is the entry's
        $copy($malek, '5116/1', $work('اشعار'));
        $inMalek = $copy($malek, '5116/2', $khulasa);

        $m = fn (string $text) => "\u{E020}OB\u{E021}{$text}\u{E022}";
        $text = "( مثنوي خلاصة الأحكام ) لصبا الكاشاني، منظومة. منه (" . $m('المجلس') . ' 1012) كتابته 1266 و (' . $m('إلهيات: ')
            . "\n\n799 د) و (" . $m('سپهسالار:') . ' 404) و (' . $m('الملك:') . ' 5116) كلها من القرن الثالث عشر.';
        $page = $this->page(19, 170, $text, ['مثنوي خلاصة الأحكام']);
        foreach ([$majlis, $ilahiyat, $sepahsalar, $malek] as $ordinal => $lib) {
            DB::table('source_page_links')->insert(['source_page_id' => $page, 'ordinal' => $ordinal, 'kind' => 'OB', 'text' => '',
                'target_type' => Library::class, 'target_id' => $lib->id, 'method' => 'form']);
        }

        $this->artisan('sources:link-page-refs', ['source' => 'dharia', '--only' => 'copies'])->assertSuccessful();
        $this->assertSame([
            [0, $inMajlis->id, 'exact', '1012'], [1, $inIlahiyat->id, 'exact', '799 د'], [3, $inMalek->id, 'part', '5116'],
        ], DB::table('source_page_links')->where('target_type', Manuscript::class)->orderBy('ordinal')->get()
            ->map(fn ($l) => [$l->ordinal, $l->target_id, $l->method, $l->span])->all());
        // the library's links stay
        $this->assertSame(4, DB::table('source_page_links')->where('target_type', Library::class)->count());

        $html = $this->get('/sources/dharia/19/page/170')->assertOk()->getContent();
        $url = route('manuscripts.show', $inMajlis->id);
        $this->assertStringContainsString('<a href="' . $url . '" id="m0" class="book-sheet__mark book-sheet__mark--copy"', $html);
        $this->assertMatchesRegularExpression('~<a href="' . preg_quote($url, '~') . '" class="book-sheet__mark book-sheet__mark--copy"[^>]*>۱۰۱۲</a>~u', $html);
        $this->assertStringContainsString('<a href="' . route('libraries.show', $sepahsalar) . '" id="m2" class="book-sheet__mark book-sheet__mark--library"', $html);

        // an editor's rejection of the copy brings the library back, and stays on the next run
        DB::table('source_page_links')->where('target_id', $inMajlis->id)->where('target_type', Manuscript::class)->update(['rejected_at' => now()]);
        $this->artisan('sources:link-page-refs', ['source' => 'dharia', '--only' => 'copies'])->assertSuccessful();
        $this->assertSame(3, DB::table('source_page_links')->where('target_type', Manuscript::class)->count());
        $html = $this->get('/sources/dharia/19/page/170')->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . route('libraries.show', $majlis) . '" id="m0" class="book-sheet__mark book-sheet__mark--library"', $html);

        $this->followingRedirects()->get(route('manuscripts.show', $inIlahiyat->id))->assertOk()->assertSee('یادکرد در منابع')
            ->assertSee('/sources/dharia/19/page/170#m1', false)->assertSee('<mark class="bg-transparent text-ink font-bold">إلهيات: ۷۹۹ د</mark>', false);
    }

    public function test_a_reference_to_the_source_itself_links_its_entry_or_page()
    {
        $named = $this->entry(7, 161, 'خسرو و گل');
        $this->entry(7, 161, 'خسرو و شیرین');
        $own = $this->entry(7, 162, 'خسروي‌نامه');
        $this->entry(7, 162, 'خشخاش‌نامه');
        $this->page(7, 161);
        $this->page(7, 162);
        $other = $this->page(18, 232);

        $wb = fn (string $kind, string $text) => "\u{E020}{$kind}\u{E021}{$text}\u{E022}";
        $text = '( مثنوي خسرو و گل ) ' . $wb('SB', 'لعطار النيشابوري') . '، مر في (161:7) بعنوان ' . $wb('WB', 'خسرو و گل')
            . ' و في (232:18) بعنوان ' . $wb('EB', 'گل و خسرو') . " كما مر.\n\n"
            . "( مثنوي خسروي‌نامه ) لآقا محمد إبراهيم النواب، مر (162:7). و ترجمه في لباب الألباب (ج 2 - ص 282) و (7:\n\n182).";
        $page = $this->page(19, 170, $text, ['مثنوي خسرو و گل', 'مثنوي خسروي‌نامه']);

        $this->artisan('sources:link-page-refs', ['source' => 'dharia', '--only' => 'references'])->assertSuccessful();
        $links = DB::table('source_page_links')->where('kind', 'ref')->orderBy('offset')->get();
        $this->assertSame([
            ['(161:7)', SourceItem::class, $named->id, 'title'],
            ['(232:18)', SourcePage::class, $other, 'page-titled'],
            ['(162:7)', SourceItem::class, $own->id, 'context'],
        ], $links->map(fn ($l) => [$l->span, $l->target_type, $l->target_id, $l->method])->all());
        $this->assertSame('(161:7)', mb_substr($text, $links[0]->offset, 7));

        $html = $this->get('/sources/dharia/19/page/170')->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . $named->url() . '" id="r' . $links[0]->offset . '" class="book-sheet__mark book-sheet__mark--entry"', $html);
        $this->assertStringContainsString('<a href="' . route('source-pages.show', ['dharia', 18, 232]) . '" id="r' . $links[1]->offset . '" class="book-sheet__mark book-sheet__mark--page"', $html);
        // the reference to another book stays plain
        $this->assertStringNotContainsString('>(ج ۲ - ص ۲۸۲)</a>', $html);

        // the entry named lists the reference
        $this->get($named->url())->assertOk()->assertSee('/sources/dharia/19/page/170#r' . $links[0]->offset, false);

        // a page whose text changed shows no link where the words are no longer those linked
        DB::table('source_pages')->where('id', $page)->update(['text' => 'X' . $text]);
        $html = $this->get('/sources/dharia/19/page/170')->assertOk()->getContent();
        $this->assertStringNotContainsString('book-sheet__mark--page', $html);
    }

    public function test_titles_agree_and_a_citation_is_placed_by_the_sources_volumes()
    {
        $this->assertTrue(Titles::agree('مثنوي خلاصة الأحكام', 'خلاصة الاحکام'));
        $this->assertTrue(Titles::agree('روضة الواعظين', 'روضة الواعظین و تبصرة المتعظین'));
        // a work made on another, and a poet's name, one word, in another title
        $this->assertFalse(Titles::agree('مقدمه رباعيات أبو سعيد', 'رباعیات ابوسعید'));
        $this->assertFalse(Titles::agree('شرح تجريد الاعتقاد', 'تجرید الاعتقاد'));
        $this->assertFalse(Titles::agree('مكتوب سيد محمد نوربخش', 'دیوان نوربخش'));
        $this->assertFalse(Titles::agree('ملاحظات حول ديوان ناصر خسرو', 'دیوان ناصر خسرو'));
        $this->assertFalse(Titles::agree('اللآلي', 'جلالیة'));
        $this->assertTrue(Titles::agree('رسالة في القضاء و القدر', 'القضاء و القدر'));

        $this->assertSame([7, 161], BibliographyLinks::place('dharia', 161, 7));
        // «(7:182)», the volume first, as only that fits
        $this->assertSame([7, 182], BibliographyLinks::place('dharia', 7, 182));
        $this->assertSame([3, 18], BibliographyLinks::place('dharia', 3, 18, true));
        // a page after the volume's last
        $this->assertNull(BibliographyLinks::place('dharia', 900, 7));
    }

    public function test_a_source_without_forms_is_refused()
    {
        Source::create(['code' => 'kashf', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 30, 'title' => 'کشف الظنون']);
        $this->artisan('sources:link-page-refs', ['source' => 'kashf'])->assertFailed();
    }
}
