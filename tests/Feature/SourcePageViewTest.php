<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Support\BibliographyLinks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The text page of a printed page (plan docs/plans/source-page-text.md): /sources/monzavi/6/page/361. Monzavi's
 * fixtures are volume 6 pp. 360-362 from Noor; the al-Dhari'a-like source keeps its text by entry only.
 */
class SourcePageViewTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/monzavi/';

    public function test_a_page_shows_its_text_cut_at_its_entries_with_the_pages_around_it()
    {
        $volume = $this->monzavi();
        $items = SourceItem::where('source_volume_id', $volume->id)->get()->keyBy('item_key');

        $response = $this->get('/sources/monzavi/6/page/361')->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            // the path above the page leads to the sources, the source and its volume
            ->assertSee('<a href="' . route('sources.index') . '" class="hover:text-cinnabar">منابع</a>', false)
            ->assertSee('<a href="' . route('sources.show', 'monzavi') . '#v6" class="hover:text-cinnabar">ج ۶</a>', false)
            ->assertSee('فهرستواره کتاب‌های فارسی، ج ۶، ص ۳۶۱', false)
            // the entry that runs on from p. 360, its label leading to that page; then the three that start here,
            // whose headings link no work yet and so are not links: the entry's own page is only beside the sheet
            ->assertSee('href="' . url('/sources/monzavi/6/page/360') . '" class="book-sheet__cont"', false)
            ->assertSee('ادامه‌ی «تحفة الامیر» از ص ۳۶۰', false)
            ->assertSee('<span class="book-sheet__heading" data-entry-heading>*تحفة الایادی.</span>', false)
            ->assertSee('href="' . $items['361#2']->url() . '" class="flex', false)
            ->assertDontSee('data-work-link', false)
            ->assertSee('همان سراینده و نویسندۀ پرکاری', false)
            ->assertSee('/sources/monzavi/6/page/360', false)
            ->assertSee('/sources/monzavi/6/page/362', false)
            ->assertDontSee('noor.example', false);
        $this->assertSame(['361#1', '361#2', '361#3'], $this->headings($response->getContent()));
        $this->assertSame(1, substr_count($response->getContent(), 'data-entry-continued'));

        // the page before the first entry is front matter; the volume's first page has no page before it
        DB::table('source_pages')->insert(['source_volume_id' => $volume->id, 'page' => 5, 'text' => "مقدمه\nسخن مؤلف"]);
        $this->get('/sources/monzavi/6/page/5')->assertOk()->assertSee('پیش‌گفتار جلد ۶', false)
            ->assertSee('/sources/monzavi/6/page/360', false)->assertDontSee('rel="prev"', false);
        $this->get('/sources/monzavi/6/page/360')->assertOk()->assertSee('/sources/monzavi/6/page/5', false);

        // a blank printed page
        DB::table('source_pages')->insert(['source_volume_id' => $volume->id, 'page' => 6, 'text' => '']);
        $this->get('/sources/monzavi/6/page/6')->assertOk()->assertSee('این صفحه در چاپ متنی ندارد', false);
    }

    public function test_the_last_page_of_a_volume_turns_to_the_next_volume()
    {
        $volume = $this->monzavi();
        $seven = SourceVolume::create(['source_id' => $volume->source_id, 'volume_number' => 7, 'facsimile_code' => 'monzavi']);
        DB::table('source_pages')->insert(['source_volume_id' => $seven->id, 'page' => 1, 'text' => 'جلد هفتم']);

        $this->get('/sources/monzavi/6/page/362')->assertOk()->assertSee('/sources/monzavi/7/page/1', false)->assertSee('ج ۷، ص ۱', false);
        $this->get('/sources/monzavi/7/page/1')->assertOk()->assertSee('/sources/monzavi/6/page/362', false);
    }

    public function test_a_missing_page_is_not_found_and_a_page_cited_in_the_volume_before_moves_to_its_own()
    {
        $this->monzavi();

        $this->get('/sources/monzavi/6/page/400')->assertNotFound();
        $this->get('/sources/monzavi/9/page/361')->assertNotFound();
        $this->get('/sources/nothing/1/page/1')->assertNotFound();
        // volumes 1-5 run on: page 3366 is volume 5's (config/bibliography.php)
        $this->get('/sources/monzavi/4/page/3366')->assertRedirect('/sources/monzavi/5/page/3366')->assertStatus(301);
    }

    public function test_a_source_kept_by_entry_gives_its_entries_parts_with_the_page_image()
    {
        $source = Source::create(['code' => 'dharia-test', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'الذریعة']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 3, 'facsimile_code' => 'dharia-test']);
        $item = fn (string $key, int $position, int $from, int $to, string $text, array $breaks = []) => SourceItem::create([
            'source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 3, 'item_key' => $key, 'position' => $position,
            'kind' => SourceItem::KIND_ENTRY, 'entry_number' => (int) $key, 'title' => 'التحفة', 'title_normalized' => 'التحفه', 'page_start' => $from, 'page_end' => $to,
            'text' => $text, 'metadata' => ['page_breaks' => $breaks]]);
        $item('1447', 1, 402, 403, "( 1447 : التحفة ) في الطب وكثير\nمن مجرباته .", [[mb_strlen("( 1447 : التحفة ) في الطب وكثير\n"), 403]]);
        $item('1448', 2, 403, 403, '( 1448 : التحفة ) أرجوزة في المبدأ والمعاد .');

        $response = $this->get('/sources/dharia-test/3/page/403')->assertOk()
            ->assertSee('ادامه‌ی «التحفة» از ص ۴۰۲', false)
            ->assertSee('من مجرباته', false)
            ->assertSee('أرجوزة في المبدأ والمعاد', false)
            ->assertDontSee('في الطب وكثير', false)
            ->assertSee('دیدن برگه', false)
            ->assertSee('catalogs/dharia-test/v03/p0403.webp', false)
            ->assertSee('/sources/dharia-test/3/page/402', false);
        $this->assertSame(['1448'], $this->headings($response->getContent()));
        // the page after the last entry's is not there
        $this->get('/sources/dharia-test/3/page/404')->assertNotFound();
        // the entry page links its pages' texts
        $this->get('/sources/dharia-test/3/1447')->assertOk()->assertSee('/sources/dharia-test/3/page/403', false);
    }

    public function test_an_entry_whose_number_is_printed_on_its_own_line_is_found_and_its_title_linked()
    {
        // al-Dhari'a's volume 26 from Noor: «[ 972 ]», then the title in parentheses, where the entry reads «( 972 : …»
        $source = Source::create(['code' => 'dharia-test', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'الذریعة']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 26, 'facsimile_code' => 'dharia-test']);
        DB::table('source_pages')->insert(['source_volume_id' => $volume->id, 'page' => 194,
            'text' => "هر بعض مطبوعاته.\n\n[ 972 ]\n\n( ترجمه تاريخ عباسه ) أصله لجرجى زيدان و المترجم ميرزا"]);
        $item = SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 26, 'item_key' => '972',
            'position' => 1, 'kind' => SourceItem::KIND_ENTRY, 'entry_number' => 972, 'title' => 'ترجمه تاريخ عباسه', 'title_normalized' => 'ترجمه تاريخ عباسه',
            'page_start' => 194, 'page_end' => 194, 'text' => '( 972 : ترجمه تاريخ عباسه ) أصله لجرجى زيدان و المترجم ميرزا']);

        $response = $this->get('/sources/dharia-test/26/page/194')->assertOk()
            ->assertSee('<span class="book-sheet__heading" data-entry-heading>[ ۹۷۲ ]</span>', false)
            ->assertSee('<span class="book-sheet__heading">( ترجمه تاريخ عباسه )</span> أصله لجرجى زيدان', false);
        $this->assertSame(['972'], $this->headings($response->getContent()));

        // tied to a work, the title in parentheses leads to it, with ⓘ while the tie is a machine's
        $work = Work::create(['primary_title' => 'ترجمه تاريخ عباسه', 'clean_title' => 'ترجمه تاريخ عباسه', 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $source->id, 'source_volume_id' => $volume->id,
            'source_item_id' => $item->id, 'volume_number' => 26, 'page_start' => 194, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_MATCH,
            'status' => SourceEntry::STATUS_UNVERIFIED, 'confidence' => 80]);
        $html = $this->get('/sources/dharia-test/26/page/194')->assertOk()
            ->assertSee('<span class="book-sheet__heading" data-entry-heading>[ ۹۷۲ ]</span>', false)
            ->assertSee('<a href="' . route('works.show', $work) . '" class="book-sheet__heading book-sheet__heading--work" title="ترجمه تاريخ عباسه" data-work-link>( ترجمه تاريخ عباسه )</a>', false)
            ->getContent();
        // ⓘ by the heading and by the work beside the sheet
        $this->assertSame(2, substr_count($html, 'rounded-full text-sky-700'));
    }

    public function test_a_catalogue_page_links_its_works_headings_and_copies_lines_in_two_columns()
    {
        // Fankha vol. 2 p. 926 in short: two works with the same heading line, and their copies. Saving a record
        // makes Fankha's source and volume (App\Sources\SourceEntrySync), so the pages come after the records.
        $works = [];
        $authors = [];
        foreach (['الاربعون حديثاً (قمی)' => 'قمی، علیقلی بن قره چغای', 'الاربعون حديثاً (یادگار)' => 'محمد جعفر بن میرزا یادگار'] as $title => $name) {
            $authors[] = $author = \App\Models\Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'p-' . uniqid(), 'is_author' => true]);
            $works[] = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'w-' . uniqid(), 'volume_number' => 2, 'page_start' => 926,
                'author_id' => $author->id, 'author_name' => $name]);
        }
        $copies = [
            Manuscript::create(['work_id' => $works[0]->id, 'shelfmark' => '7728', 'volume_number' => 2, 'page_start' => 926]),
            Manuscript::create(['work_id' => $works[1]->id, 'shelfmark' => '13448', 'volume_number' => 2, 'page_start' => 926]),
        ];
        $source = Source::firstOrCreate(['code' => 'fankha'], ['kind' => Source::KIND_UNION_CATALOGUE, 'rank' => 90, 'title' => 'فهرستگان نسخه‌های خطی ایران (فنخا)']);
        $volume = SourceVolume::firstOrCreate(['source_id' => $source->id, 'volume_number' => 2], ['facsimile_code' => 'fankha']);
        DB::table('source_pages')->insert(['source_volume_id' => $volume->id, 'page' => 926, 'text' => implode("\n", [
            'انجام: و اما الكلمات اللائى فى الادب.',
            '', '● الاربعون حديثاً / حدیث / عربی', 'al-arba‘ūn-a ḥadīṯā', '', 'قمی، علیقلی بن قره چغای، 1020؟ق', '',
            '1. تهران؛ مجلس؛ شماره نسخه: 7728', 'خط: نستعلیق، کاتب = مؤلف',
            '', '● الاربعون حديثاً / حدیث / عربی', 'al-arba‘ūn-a ḥadīṯā', '', 'محمد جعفر بن میرزا یادگار، ق 11 قمری', '',
            'قم؛ مرعشی؛ شماره نسخه: 13448', 'آغاز: الحديث الثانى',
        ])]);
        foreach ($works as $i => $work) {
            $this->entry($source, $volume, $work, ['entry_text' => "● الاربعون حديثاً / حدیث / عربی\nal-arba‘ūn-a ḥadīṯā\n\n" . ['قمی، علیقلی بن قره چغای، 1020؟ق', 'محمد جعفر بن میرزا یادگار، ق 11 قمری'][$i]]);
        }
        $this->entry($source, $volume, $copies[0], ['copy_text' => "1. تهران؛ مجلس؛ شماره نسخه: 7728\nخط: نستعلیق"]);
        $this->entry($source, $volume, $copies[1], ['copy_text' => 'قم؛ مرعشی؛ شماره نسخه: 13448']);

        $html = $this->get('/sources/fankha/2/page/926')->assertOk()
            ->assertSee('book-sheet--columns', false)
            ->assertSee('آثار این صفحه', false)
            ->assertSee('<a href="' . route('manuscripts.show', $copies[0]) . '" class="book-sheet__mark book-sheet__mark--manuscript"', false)
            ->assertSee('data-record-link="manuscript">۱. تهران؛ مجلس؛ شماره نسخه: ۷۷۲۸</a>', false)
            // a transliteration reads left to right, with its own digits
            ->assertSee('<span dir="ltr" class="book-sheet__latin">al-arba‘ūn-a ḥadīṯā</span>', false)
            ->getContent();
        // the heading printed twice links each work in turn
        preg_match_all('/<a href="([^"]+)" class="book-sheet__mark book-sheet__mark--work"/u', $html, $m);
        $this->assertSame([route('works.show', $works[0]), route('works.show', $works[1])], $m[1]);
        $this->assertSame(2, substr_count($html, 'data-record-link="manuscript"'));
        // each work's author line links the author
        $this->assertStringContainsString('<a href="' . route('people.show', $authors[0]) . '" class="book-sheet__mark book-sheet__mark--person" title="قمی، علیقلی بن قره چغای" data-record-link="person">قمی، علیقلی بن قره چغای، ۱۰۲۰؟ق</a>', $html);
        $this->assertStringContainsString(route('people.show', $authors[1]), $html);

        // a one-column source keeps one column
        $this->assertSame(1, (int) config('catalogs.columns.monzavi', 1));
    }

    public function test_a_heading_shared_by_a_catalogues_copies_links_their_work_once()
    {
        // Masjid-e A'zam: «1- القرآن الکریم» heads the copies «ش 714…» and «ش 1392…»
        $source = Source::create(['code' => 'masjid-test', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 30, 'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1, 'facsimile_code' => 'masjid-test']);
        DB::table('source_pages')->insert(['source_volume_id' => $volume->id, 'page' => 17,
            'text' => "1- القرآن الکریم\nش 714، نسخ، بی کا، بی تا.\n\nش 1392، نسخ، بی کا، بی تا. قسمتی از سوره بقره است."]);
        $work = Work::create(['primary_title' => 'القرآن الکریم', 'clean_title' => 'القرآن الکریم', 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        foreach (['714', '1392'] as $shelfmark) {
            $copy = Manuscript::create(['work_id' => $work->id, 'shelfmark' => $shelfmark, 'volume_number' => 1, 'page_start' => 17]);
            $this->entry($source, $volume, $copy, ['entry_text' => '1- القرآن الکریم', 'copy_text' => "ش {$shelfmark}، نسخ، بی کا، بی تا.", 'page_start' => 17, 'text_page' => 17, 'shelfmark_page' => 17]);
        }

        $html = $this->get('/sources/masjid-test/1/page/17')->assertOk()->assertDontSee('book-sheet--columns', false)->getContent();
        $this->assertSame(1, substr_count($html, 'data-record-link="work"'));
        $this->assertSame(2, substr_count($html, 'data-record-link="manuscript"'));
    }

    public function test_a_poem_is_set_as_verses_without_the_blank_lines_around_it()
    {
        // al-Dhari'a vol. 13 p. 4: the Noor reader writes each verse on its own line, its hemistichs apart by a tab
        $html = (string) \App\Support\PrintedPage::text("أولها:\n\nحمدا لربي واجب الوجود\tالعالم القدير و المريد\nإلى قوله:\n\n"
            . "تم الختام ثامن العشرينا\tمن شهر شعبان من السنينا\nتاسعة و مائة و مرة\tمن بعد ألف من سنين الهجرة\nيعني سنة 1209 ه‍.");

        $this->assertSame('أولها:<span class="book-poem" data-poem><span class="book-poem__h">حمدا لربي واجب الوجود</span><span class="book-poem__h">العالم القدير و المريد</span></span>'
            . 'إلى قوله:<span class="book-poem" data-poem><span class="book-poem__h">تم الختام ثامن العشرينا</span><span class="book-poem__h">من شهر شعبان من السنينا</span>'
            . '<span class="book-poem__h">تاسعة و مائة و مرة</span><span class="book-poem__h">من بعد ألف من سنين الهجرة</span></span>يعني سنة ۱۲۰۹ ه‍.', $html);
    }

    public function test_noors_marks_set_a_verse_a_hadith_and_latin_and_run_over_lines()
    {
        $text = "قال \u{E020}ayah 18:46\u{E021}(وَ الْبٰاقِيٰاتُ)\u{E022} و \u{E020}latin\u{E021}A. Storey 1927\u{E022} و \u{E020}hadith\u{E021}قال \u{E020}FB\u{E021}النبي ص\u{E022}\nلا\u{E022} تم";
        $html = (string) \App\Support\PrintedPage::text($text);

        $this->assertStringContainsString('<span class="book-ayah" title="سوره‌ی کهف، آیه‌ی ۴۶" data-ayah="18:46">(وَ الْبٰاقِيٰاتُ)</span>', $html);
        // a passage in Latin letters keeps its own digits
        $this->assertStringContainsString('<bdi dir="ltr" class="book-latin">A. Storey 1927</bdi>', $html);
        // the hadith runs over the line break: closed at the line's end, opened again on the next
        $this->assertStringContainsString('<span class="book-hadith">قال <span data-noor="FB">النبي ص</span></span>' . "\n" . '<span class="book-hadith">لا</span> تم', $html);
        // whatever reads a page for its words gets them without the marks
        $this->assertSame("قال (وَ الْبٰاقِيٰاتُ) و A. Storey 1927 و قال النبي ص\nلا تم", \App\Support\PageMarks::strip($text));

        // a line with only a mark's opening is blank: it goes before a poem as a blank line would
        $this->assertStringStartsWith('أولها:<span class="book-poem"', (string) \App\Support\PrintedPage::text("أولها:\n\n\u{E020}IB\u{E021}\nحمدا\tالعالم\u{E022}"));
    }

    public function test_an_entry_whose_first_line_has_marks_is_found_and_its_heading_shows_none()
    {
        // al-Dhari'a vol. 9 p. 218: the heading runs on with its paragraph, whose names Noor tags
        $source = Source::create(['code' => 'dharia-test', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'الذریعة']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 9, 'facsimile_code' => 'dharia-test']);
        DB::table('source_pages')->insert(['source_volume_id' => $volume->id, 'page' => 218,
            'text' => "( 1333 : ديوان حاجي أردبيلي ) أورد شعره في (\u{E020}WB\u{E021}گلشن\u{E022} - ص 133)."]);
        SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 9, 'item_key' => '1333', 'position' => 1,
            'kind' => SourceItem::KIND_ENTRY, 'entry_number' => 1333, 'title' => 'ديوان حاجي أردبيلي', 'title_normalized' => 'ديوان حاجي اردبيلي',
            'page_start' => 218, 'page_end' => 218, 'text' => '( 1333 : ديوان حاجي أردبيلي ) أورد شعره في (گلشن - ص 133).']);
        // an unnumbered entry under it: its heading alone is the heading too
        DB::table('source_pages')->where('source_volume_id', $volume->id)->update(['text' => DB::raw("CONCAT(text, '\n\n( ديوان محمد مذهب ) له المعمى باسم جليل.')")]);
        SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 9, 'item_key' => '1333.1', 'position' => 2,
            'kind' => SourceItem::KIND_SUB_ENTRY, 'title' => 'ديوان محمد مذهب', 'title_normalized' => 'ديوان محمد مذهب',
            'page_start' => 218, 'page_end' => 218, 'text' => '( ديوان محمد مذهب ) له المعمى باسم جليل.']);

        $html = $this->get('/sources/dharia-test/9/page/218')->assertOk()
            // only the heading is the heading; its paragraph goes on after it
            ->assertSee('<span class="book-sheet__heading" data-entry-heading>( ۱۳۳۳ : ديوان حاجي أردبيلي )</span>', false)
            ->assertSee('<span class="book-sheet__rest"> أورد شعره في (<span data-noor="WB">گلشن</span> - ص ۱۳۳).</span>', false)
            ->assertSee('<span class="book-sheet__heading" data-entry-heading>( ديوان محمد مذهب )</span>', false)
            ->getContent();
        $this->assertSame(['1333', '1333.1'], $this->headings($html));
        $this->assertStringNotContainsString("\u{E020}", $html);
    }

    public function test_a_citation_inside_a_page_text_links_the_cited_page()
    {
        // the words right before the number name the source, not all the text before it
        $cites = array_values(array_filter(BibliographyLinks::inProse('فی [الذريعة 69/13] و (ذریعه 1004/9)؛ کشف الظنون 1/500 و بدون'), fn ($s) => isset($s['cite'])));
        $this->assertSame([['dharia', 13, 69], ['dharia', 9, 1004], ['kashf', 1, 500]], array_map(fn ($s) => [$s['cite']['source'], $s['cite']['volume'], $s['cite']['page']], $cites));

        // Monzavi's «همان‌جا» cites the book cited right before it, while that is one of the site's
        $cites = array_values(array_filter(BibliographyLinks::inProse('ذریعه 280/9، همان‌جا 453/3 «تحفة...»، همان‌جا 138/19؛ مشترک 46/7، همان‌جا 53/10'), fn ($s) => isset($s['cite'])));
        $this->assertSame([['280/9', 9, 280], ['453/3', 3, 453], ['138/19', 19, 138]], array_map(fn ($s) => [$s['text'], $s['cite']['volume'], $s['cite']['page']], $cites));

        // Monzavi's page 360 cites page 361 of its own volume: the page text links it
        $this->monzavi();
        $html = (string) \App\Support\PrintedPage::text('نسخه‌ها؛ فهرستواره 361/6 و ذریعه 9999/9');
        $this->assertStringContainsString('<a href="' . route('source-pages.show', ['monzavi', 6, 361]) . '" class="book-sheet__cite" data-cite>۳۶۱/۶</a>', $html);
        // a citation of a page the site lacks, of a source with no viewer either, stays text
        $this->assertStringNotContainsString('9999/9"', $html);
        $this->assertStringContainsString('۹۹۹۹/۹', $html);
    }

    public function test_citations_of_a_text_source_link_to_the_page_text_in_the_same_tab()
    {
        $html = (string) BibliographyLinks::html('الذریعة 2/1؛ فهرستواره منزوی 361/6 و 3257/4');

        $this->assertStringContainsString('href="' . url('/sources/monzavi/6/page/361') . '" title="متن فهرستواره کتاب‌های فارسی، ج ۶، ص ۳۶۱"', $html);
        $this->assertStringContainsString('href="' . url('/sources/monzavi/5/page/3257') . '"', $html);
        $this->assertSame(1, substr_count($html, 'target="_blank"'));
        $this->assertSame([['monzavi', 6, 361], ['monzavi', 5, 3257]], array_map(fn ($c) => [$c['source'], $c['volume'], $c['page']],
            array_values(array_filter(BibliographyLinks::citations('فهرستواره منزوی 361/6 و 3257/4'), fn ($c) => $c['source'] === 'monzavi'))));
        $this->assertSame([], array_filter(BibliographyLinks::viewerPages(['فهرستواره منزوی 361/6']), fn ($v) => $v['catalog'] === 'monzavi'));
    }

    public function test_the_work_page_links_a_citation_with_no_entry_and_the_place_of_a_found_one()
    {
        $volume = $this->monzavi();
        $unmatched = $this->work('اثری بی‌مدخل', 'فهرستواره منزوی 362/6');
        $this->get(route('works.show', $unmatched))->assertOk()->assertSee('href="' . url('/sources/monzavi/6/page/362') . '"', false);

        $found = $this->work('تحفة الایادی', 'فهرستواره منزوی 361/6');
        $item = SourceItem::where('source_volume_id', $volume->id)->where('item_key', '361#2')->sole();
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $found->id, 'source_id' => $volume->source_id, 'source_volume_id' => $volume->id,
            'source_item_id' => $item->id, 'volume_number' => 6, 'page_start' => 361, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_MATCH,
            'status' => SourceEntry::STATUS_UNVERIFIED, 'confidence' => 90]);
        $this->get(route('works.show', $found))->assertOk()
            ->assertSee('href="' . url('/sources/monzavi/6/page/361') . '" title="متن صفحه در', false)
            ->assertSee($item->url(), false);
        // the entry's heading on the page text leads to the work tied to it, with ⓘ; a second work shows «+۱»
        $other = $this->work('تحفة الایادی (۲)', 'فهرستواره منزوی 361/6');
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $other->id, 'source_id' => $volume->source_id, 'source_volume_id' => $volume->id,
            'source_item_id' => $item->id, 'volume_number' => 6, 'page_start' => 361, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_MATCH,
            'status' => SourceEntry::STATUS_UNVERIFIED, 'confidence' => 70]);
        $html = $this->get('/sources/monzavi/6/page/361')->assertOk()
            ->assertSee('<a href="' . route('works.show', $found) . '" class="book-sheet__heading book-sheet__heading--work" title="تحفة الایادی" data-entry-heading data-work-link>*تحفة الایادی.</a>', false)
            ->assertSee('>+۱</a>', false)
            ->assertSee(route('works.show', $other), false)
            ->getContent();
        $this->assertSame(1, substr_count($html, 'data-work-link'));
    }

    /** Monzavi's volume 6 pp. 360-362, its pages and entries as step 13.69 imports them. */
    private function monzavi(): SourceVolume
    {
        $source = Source::create(['code' => 'monzavi', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'فهرستواره کتاب‌های فارسی',
            'short_name' => 'فهرستواره منزوی', 'unit' => 'صفحه', 'metadata' => ['config' => 'monzavi', 'images' => false]]);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 6, 'facsimile_code' => 'monzavi', 'page_first' => 1,
            'page_last' => 910, 'source_url' => 'https://noor.example/book/11578']);
        $this->artisan('sources:import-pages', ['volume' => 'monzavi', 'pages' => self::FIXTURES . 'v06-p360-362.jsonl', '--volume' => 6])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'monzavi', '--volume' => 6, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'v06-p360-362.entries.jsonl'])->assertSuccessful();

        return $volume;
    }

    private function work(string $title, string $citation): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1,
            'bibliography' => [$citation]]);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => Source::where('code', 'monzavi')->value('id'),
            'volume_number' => 6, 'page_start' => (int) preg_replace('/\D.*$/u', '', preg_replace('/^\D+/u', '', $citation)), 'role' => SourceEntry::ROLE_CITATION,
            'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY, 'status' => SourceEntry::STATUS_VERIFIED, 'citation_text' => $citation]);

        return $work;
    }

    /** A catalogue's entry for a work or copy, as the source backfill makes it from the record. */
    private function entry(Source $source, SourceVolume $volume, $record, array $text): SourceEntry
    {
        return SourceEntry::create($text + ['citable_type' => $record::class, 'citable_id' => $record->id, 'source_id' => $source->id,
            'source_volume_id' => $volume->id, 'volume_number' => $volume->volume_number, 'page_start' => 926, 'text_page' => 926,
            'role' => SourceEntry::ROLE_ENTRY, 'origin' => 'fankha', 'status' => SourceEntry::STATUS_VERIFIED]);
    }

    /** The keys of the entries whose headings start on the page, in order. */
    private function headings(string $html): array
    {
        preg_match_all('/<section class="book-sheet__entry"\s+data-entry="([^"]+)"\s*>\s*<div class="book-sheet__headline">/u', $html, $m);

        return $m[1];
    }
}
