<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as Import;
use App\Console\Commands\MatchCatalogEditionCommand as Matcher;
use App\Models\AuditLog;
use App\Models\Catalog;
use App\Models\CatalogVolume;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Arr;
use Tests\TestCase;

class CatalogReferencesTest extends TestCase
{
    private const SECRET_URL = 'https://scan-source.example/book/504138';

    protected Library $library;

    protected CatalogVolume $fankhaVolume;

    protected CatalogVolume $edition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-azam-' . uniqid()]);
        $fankha = Catalog::firstOrCreate(['code' => 'fankha'], ['name' => 'فهرستگان نسخه‌های خطی ایران', 'short_name' => 'فنخا']);
        $this->fankhaVolume = CatalogVolume::create(['title' => 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id, 'catalog_id' => $fankha->id]);
        $this->edition = CatalogVolume::create([
            'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id,
            'edition_of_id' => $this->fankhaVolume->id, 'edition_label' => '۱۳۶۵، رضا استادی',
            'facsimile_code' => 'masjid-azam-ostadi', 'page_first' => 1, 'page_last' => 827, 'source_url' => self::SECRET_URL,
        ]);
    }

    protected function manuscript(array $attributes = []): Manuscript
    {
        $work = Work::create(['primary_title' => 'دیوان قدسی', 'clean_title' => 'دیوان قدسی', 'slug' => 'work-ref-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create($attributes + [
            'work_id' => $work->id, 'library_id' => $this->library->id, 'shelfmark' => '1939',
            'catalog_volume_id' => $this->fankhaVolume->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1,
        ]);
    }

    public function test_an_edition_links_to_the_volume_fankha_cites_and_hides_its_source()
    {
        $this->assertTrue($this->edition->editionOf->is($this->fankhaVolume));
        $this->assertTrue($this->fankhaVolume->editions->first()->is($this->edition));
        $this->assertSame('فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', $this->edition->short_title);
        $this->assertArrayNotHasKey('source_url', $this->edition->toArray());
        $this->assertStringNotContainsString('scan-source', $this->edition->toJson());
    }

    public function test_a_reference_opens_the_page_where_the_entry_starts()
    {
        $ms = $this->manuscript();
        $ref = Ref::create([
            'manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2,
            'page_start' => 414, 'shelfmark_page' => 415, 'entry_number' => 606, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90,
        ]);

        $this->assertTrue($ms->catalogReferences->first()->is($ref));
        $this->assertTrue($ref->isAwaitingReview());
        $this->assertSame(1, Ref::awaitingReview()->where('manuscript_id', $ms->id)->count());
        $this->assertSame(['catalog' => 'masjid-azam-ostadi', 'catalogName' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', 'volume' => 2, 'page' => 414], $ref->viewer());

        $ref->update(['catalog_volume_id' => $this->fankhaVolume->id]);
        $this->assertNull($ref->fresh()->viewer(), 'a volume without page images has no viewer');
    }

    public function test_the_source_url_never_reaches_the_manuscript_page()
    {
        $ms = $this->manuscript();
        Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90]);

        $html = $this->get(route('manuscripts.show', $ms))->assertOk()->assertSee('ص ۴۱۴')->getContent();
        $this->assertStringNotContainsString('scan-source', $html);
        $this->assertStringNotContainsString('504138', $html);
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $json) {
            $this->assertStringNotContainsString('scan-source', $json);
        }
    }

    public function test_fankha_citations_are_parsed_into_volume_and_page()
    {
        $cases = [
            '[ف: 2 - 224]' => ['ف', 2, 224],
            '[ف: ۲-۲۲۴]' => ['ف', 2, 224],
            '[ف مخ: 2 - 817]' => ['ف مخ', 2, 817],
            '[ف مخ - 3 - 1159]' => ['ف مخ', 3, 1159],
            '[رشت و همدان: ف: -1398]' => ['رشت و همدان', null, 1398],
            '[سنا: ف2-157]' => ['سنا', 2, 157],
            '[الفبائی: 9-]' => ['الفبائی', 9, null],
            '[ف: 47/1 - 15]' => ['ف', 47, 15],
            '[ف: 1/27-9]' => ['ف', 27, 9],
            '[اهدائی رهبر: 2 اخبار 1-112]' => ['اهدائی رهبر', 2, 112],
            '[تراثنا: س13ش1-123]' => ['تراثنا', null, 123],
            '[ف: 18-5؛ فهرست رایانه‌ای ج3-730]' => ['ف', 18, 5],
            '[مختصر ف: 10]' => ['مختصر ف', null, null],
            '[رایانه]' => ['رایانه', null, null],
            '[ف مخ]' => ['ف مخ', null, null],
        ];
        foreach ($cases as $citation => [$series, $volume, $page]) {
            $this->assertSame(['series' => $series, 'volume' => $volume, 'page' => $page], Import::parse($citation), $citation);
        }
        $this->assertSame('[ف مخ: 1]', Import::rawCitation(null, "متن نسخه [ف مخ: 1]"));
        $this->assertNull(Import::rawCitation('{"catalog_citation": ""}', 'بی‌ارجاع'));
    }

    public function test_the_import_adds_one_fankha_row_per_copy_and_never_duplicates()
    {
        $ms = $this->manuscript(['metadata' => ['catalog_citation' => '[ف مخ: 2 - 817]']]);
        $bare = $this->manuscript(['catalog_volume_id' => null, 'metadata' => []]);

        $this->artisan('catalogs:import-fankha-references', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Ref::count());

        $this->artisan('catalogs:import-fankha-references')->assertSuccessful();
        $this->artisan('catalogs:import-fankha-references')->assertSuccessful();
        $rows = Ref::where('manuscript_id', $ms->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['[ف مخ: 2 - 817]', 2, 817, $this->fankhaVolume->id, Ref::SOURCE_FANKHA],
            [$rows[0]->raw_text, $rows[0]->volume_number, $rows[0]->page_start, $rows[0]->catalog_volume_id, $rows[0]->source]);
        $this->assertSame(0, Ref::where('manuscript_id', $bare->id)->count());

        // a later relink of the copy is followed on the next run
        $ms->update(['catalog_volume_id' => $this->edition->id]);
        $this->artisan('catalogs:import-fankha-references')->assertSuccessful();
        $this->assertSame([$this->edition->id], Ref::where('manuscript_id', $ms->id)->pluck('catalog_volume_id')->all());
    }

    public function test_editions_are_synced_by_natural_key()
    {
        $path = tempnam(sys_get_temp_dir(), 'editions');
        file_put_contents($path, json_encode(['editions' => [[
            'facsimile_code' => 'test-edition', 'volume_number' => 2, 'library_slug' => $this->library->slug, 'edition_of' => ['volume_number' => 2],
            'title' => 'فهرست آزمایشی - جلد دوم', 'edition_label' => '۱۳۶۵', 'page_first' => 1, 'page_last' => 10, 'source_url' => self::SECRET_URL,
        ]]], JSON_UNESCAPED_UNICODE));

        $this->artisan('catalogs:sync-editions', ['--data' => $path, '--dry-run' => true])->assertSuccessful();
        $this->assertNull(CatalogVolume::where('facsimile_code', 'test-edition')->first());
        $this->artisan('catalogs:sync-editions', ['--data' => $path])->assertSuccessful();
        $this->artisan('catalogs:sync-editions', ['--data' => $path])->expectsOutputToContain('unchanged')->assertSuccessful();
        unlink($path);

        $edition = CatalogVolume::where('facsimile_code', 'test-edition')->sole();
        $this->assertSame([$this->fankhaVolume->id, null, self::SECRET_URL, 10], [$edition->edition_of_id, $edition->catalog_id, $edition->source_url, $edition->page_last]);
        $this->assertCount(0, $edition->catalogers);
    }

    public function test_a_sync_corrects_the_cited_volume_and_detaches_a_foreign_cataloger()
    {
        $this->fankhaVolume->update(['publisher' => 'نشر نفایس مخطوطات اصفهان', 'publication_year' => '۱۳۳۷-۱۳۴۱', 'pages_count' => 623]);
        $foreign = \App\Models\Cataloger::create(['name' => 'فهرست‌نگار دیگر', 'slug' => 'foreign-' . uniqid(), 'volumes_count' => 1]);
        $own = \App\Models\Cataloger::create(['name' => 'فهرست‌نگار همین جلد', 'slug' => 'own-' . uniqid()]);
        $this->fankhaVolume->catalogers()->attach([$foreign->id => ['role' => 'cataloger'], $own->id => ['role' => 'cataloger']]);
        $path = tempnam(sys_get_temp_dir(), 'editions');
        file_put_contents($path, json_encode(['editions' => [[
            'facsimile_code' => 'test-edition', 'volume_number' => 2, 'library_slug' => $this->library->slug,
            'edition_of' => ['volume_number' => 2, 'set' => ['publisher' => 'مؤسسه الجواد', 'publication_year' => 'مخطوط', 'pages_count' => null],
                'detach_catalogers' => ['فهرست‌نگار دیگر']],
            'title' => 'فهرست آزمایشی - جلد دوم', 'page_first' => 1, 'page_last' => 10,
        ]]], JSON_UNESCAPED_UNICODE));

        $this->artisan('catalogs:sync-editions', ['--data' => $path, '--dry-run' => true])->expectsOutputToContain('correct: volume')->assertSuccessful();
        $this->assertSame('۱۳۳۷-۱۳۴۱', $this->fankhaVolume->fresh()->publication_year);
        $this->artisan('catalogs:sync-editions', ['--data' => $path])->assertSuccessful();
        $this->artisan('catalogs:sync-editions', ['--data' => $path])->doesntExpectOutputToContain('correct: volume')->assertSuccessful();
        unlink($path);

        $volume = $this->fankhaVolume->fresh();
        $this->assertSame(['مؤسسه الجواد', 'مخطوط', null], [$volume->publisher, $volume->publication_year, $volume->pages_count]);
        $this->assertSame([$own->id], $volume->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame(0, $foreign->fresh()->volumes_count);
    }

    public function test_treatises_of_a_composite_volume_are_matched_by_shelfmark_part()
    {
        $first = $this->manuscript(['shelfmark' => '5/1']);
        $first->work->update(['primary_title' => 'الحبل المتین']);
        $second = $this->manuscript(['shelfmark' => '5/2']);
        $second->work->update(['primary_title' => 'الوجیزة فی الدرایة']);
        $missing = $this->manuscript(['shelfmark' => '5/3']);
        $shifted = $this->manuscript(['shelfmark' => '5/1']);
        $shifted->work->update(['primary_title' => 'الوجیزة']);
        $later = $this->manuscript(['shelfmark' => '17/2']);
        $later->work->update(['primary_title' => 'صیغ العقود و الایقاعات']);
        $path = tempnam(sys_get_temp_dir(), 'pages') . '.jsonl';
        $pages = [
            [7, "( شماره 5 )\n\n1 - حبل المتین «برگ 1 - 143» (فقه - عربی)\nاز: شیخ بهاء الدین\n\n2 - الوجیزة (144 - 145) (درایه - عربی)\nاز: همو\n145 برگ، 21 × 29\n\n( 17 )\n\n1 - تحفة الأبرار «1 - 246» (فقه - فارسی)\nاز: شفتی"],
            [8, "2 ـ صیغ العقود والایقاعات «247 ـ 260» (فقه ـ عربی)\nاز: ؟\n(1)\n3 - رسالة اخری (فقه - عربی)"],
        ];
        file_put_contents($path, implode("\n", array_map(fn ($p) => json_encode(['page' => $p[0], 'text' => $p[1]], JSON_UNESCAPED_UNICODE), $pages)));

        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $path])->assertSuccessful();
        unlink($path);

        $refs = Ref::where('source', Ref::SOURCE_AI_MATCH)->get()->keyBy('manuscript_id');
        $this->assertSame([7, 1, Matcher::METHOD_TITLE], [$refs[$first->id]->page_start, $refs[$first->id]->entry_number, $refs[$first->id]->match_method]);
        $this->assertSame([7, 2, Matcher::METHOD_SINGLE_PAGE], [$refs[$second->id]->page_start, $refs[$second->id]->entry_number, $refs[$second->id]->match_method]);
        $this->assertSame([8, 2, Matcher::METHOD_TITLE], [$refs[$later->id]->page_start, $refs[$later->id]->entry_number, $refs[$later->id]->match_method]);
        $this->assertStringStartsWith('( 17 )', $refs[$later->id]->raw_text);
        $this->assertArrayNotHasKey($missing->id, $refs->all());
        // numbered 1 by Fankha, but only the second treatise fits: taken, with a note for the reviewer
        $this->assertSame([2, Matcher::METHOD_TITLE], [$refs[$shifted->id]->entry_number, $refs[$shifted->id]->match_method]);
        $this->assertStringContainsString('ردیف 2', $refs[$shifted->id]->notes);
        $this->assertSame('حبل المتین', Matcher::entryTitle('حبل المتین «برگ 1 - 143»'));
        $this->assertSame('روزنامه «ساعت نامه»', Matcher::entryTitle('روزنامه «ساعت نامه»'));
    }

    public function test_a_weak_match_gives_way_to_a_title_match_in_another_volume_of_the_printing()
    {
        $volume1 = CatalogVolume::create(['title' => 'فهرست - جلد اول', 'volume_number' => 1, 'library_id' => $this->library->id,
            'edition_of_id' => $this->fankhaVolume->id, 'facsimile_code' => 'masjid-azam-ostadi']);
        $ms = $this->manuscript(['shelfmark' => '3950']);
        $ms->work->update(['primary_title' => 'تحفة الزائر']);
        $write = function (array $pages, int $volume) {
            $path = tempnam(sys_get_temp_dir(), 'pages') . '.jsonl';
            file_put_contents($path, implode("\n", array_map(fn ($p) => json_encode(['page' => $p[0], 'text' => $p[1]], JSON_UNESCAPED_UNICODE), $pages)));
            $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $path, '--volume' => $volume])->assertSuccessful();
            unlink($path);
        };

        // volume 1 first: the number alone stands under the Quran, a weak match
        $write([[17, "1- القرآن الکریم\nش 3950، نسخ"]], 1);
        $this->assertSame(Matcher::METHOD_SHELFMARK_ONLY, Ref::where('catalog_volume_id', $volume1->id)->sole()->match_method);
        // volume 2 confirms it by title, and the weak row of volume 1 goes
        $write([[90, "153- تحفه الزائر (دعا وزیارت، فارسی)\nش 3950، 20 برگ"]], 2);
        $this->assertSame(0, Ref::where('catalog_volume_id', $volume1->id)->count());
        $this->assertSame(Matcher::METHOD_TITLE, Ref::where('catalog_volume_id', $this->edition->id)->sole()->match_method);
        // and running volume 1 again does not bring it back
        $write([[17, "1- القرآن الکریم\nش 3950، نسخ"]], 1);
        $this->assertSame(0, Ref::where('catalog_volume_id', $volume1->id)->count());
    }

    private function pages(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pages') . '.jsonl';
        $pages = [
            [414, "606- دیوان قاسمی (فارسی)\nاز: قاسمی\nآغاز: «ای دل و جان عاشقان»"],
            [415, "انجام: «صلوات خدای»\nش ۱۹۳۹، ۲۰۷ برگ، نستعلیق\n\n607- دیوان قدسی (فارسی)\nاز: قدسی\nش 3543، 164 برگ\nش 2000، جنگ"],
            [416, "608- جنگ اشعار = بیاض (فارسی)\nش 2000/2، 50 برگ\nش 1939، 10 برگ"],
        ];
        file_put_contents($path, implode("\n", array_map(fn ($p) => json_encode(['page' => $p[0], 'text' => $p[1]], JSON_UNESCAPED_UNICODE), $pages)));

        return $path;
    }

    public function test_titles_are_compared_like_the_pilot()
    {
        $this->assertSame('حدیقه شیعه', Matcher::norm('حديقة الشيعة'));
        $this->assertSame('تحریر مساکن', Matcher::norm('رساله در تحریر المساکن'));
        $this->assertEqualsWithDelta(1.0, Matcher::similarity('دیوان قدسی', 'دیوان قدسی'), 0.001);
        $this->assertEqualsWithDelta(0.75, Matcher::similarity('ابجد', 'ابجه'), 0.001); // 3 of 4 + 4 characters
        $this->assertSame(0.0, Matcher::similarity('ا', 'ب'));
    }

    public function test_copies_are_matched_by_shelfmark_and_title_to_the_page_where_the_entry_starts()
    {
        $ms = $this->manuscript(['shelfmark' => '1939']);
        $ms->work->update(['primary_title' => 'دیوان قاسمی']);
        $generic = $this->manuscript(['shelfmark' => '2000']);
        $generic->work->update(['primary_title' => 'مجموعه']);
        $absent = $this->manuscript(['shelfmark' => '9999']);
        $pages = $this->pages();

        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Ref::count());
        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages])->assertSuccessful();

        $ref = Ref::where('manuscript_id', $ms->id)->sole();
        $this->assertSame([Ref::SOURCE_AI_MATCH, Ref::STATUS_UNVERIFIED, Matcher::METHOD_TITLE, 100, 414, 415, 415, 606, $this->edition->id, 2],
            [$ref->source, $ref->status, $ref->match_method, $ref->confidence, $ref->page_start, $ref->page_end, $ref->shelfmark_page, $ref->entry_number, $ref->catalog_volume_id, $ref->volume_number]);
        $this->assertStringContainsString('ش 1939', $ref->raw_text);

        // «2000» stands in two entries on two pages and the title fits neither: recorded with low confidence
        $low = Ref::where('manuscript_id', $generic->id)->sole();
        $this->assertSame(Matcher::METHOD_AMBIGUOUS, $low->match_method);
        $this->assertSame(10, $low->confidence);
        $this->assertStringContainsString('جاهای دیگر', $low->notes);
        $this->assertSame(0, Ref::where('manuscript_id', $absent->id)->count());

        // a reviewed match is kept as it is on the next run, and nothing is duplicated
        $ref->update(['status' => Ref::STATUS_REJECTED, 'page_start' => 1]);
        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages])->assertSuccessful();
        $this->assertSame([1, Ref::STATUS_REJECTED], [$ref->fresh()->page_start, $ref->fresh()->status]);
        $this->assertSame(2, Ref::where('source', Ref::SOURCE_AI_MATCH)->count());
        unlink($pages);
    }

    private function writePages(array $pages): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pages') . '.jsonl';
        file_put_contents($path, implode("\n", array_map(fn ($p) => json_encode(['page' => $p[0], 'text' => $p[1]], JSON_UNESCAPED_UNICODE), $pages)));

        return $path;
    }

    public function test_the_entry_and_the_copy_line_are_taken_from_the_printed_pages()
    {
        $ms = $this->manuscript(['shelfmark' => '2953']);
        $ms->work->update(['primary_title' => 'منیة المرید']);
        $other = $this->manuscript(['shelfmark' => '584']);
        $other->work->update(['primary_title' => 'منیة المرید']);
        $last = $this->manuscript(['shelfmark' => '4134']);
        $last->work->update(['primary_title' => 'یوسف و زلیخا']);
        // the description breaks at the page and goes on; the copies follow, one with a second line; the index of titles closes the volume
        $path = $this->writePages([
            [549, "ش 509، 188 برگ.\n\n684- منیة المرید فی آداب المفید والمستفید (اخلاق، عربی)\nاز: شهید ثانی (966 ق)\nآداب تعلیم و تعلم را با استفاده از آیات و روایات و سخن\n"],
            [550, "بزرگان در یک مقدمه بیان نموده است.\nآغاز: «اعلم ان اللّه سبحانه...».\nش 584، 138 برگ، نسخ محمد بن علی در .1086\nآغازش افتادگی دارد.\n\nش 2953، 122 برگ، 10×15، نسخ در 1252. تصحیح شده است.\n\nهر دو نسخه در یک جلد آمده است.\n\n685- یوسف و زلیخا (فارسی)\nاز: جامی\nش 4134، 104 برگ، اول وآخر ناقص.\n"],
            [551, "فهرست نام کتاب ها\nآتشکده آذر 5\n"],
        ]);

        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $path])->assertSuccessful();
        unlink($path);

        $ref = Ref::where('manuscript_id', $ms->id)->sole();
        $this->assertSame("684- منیة المرید فی آداب المفید والمستفید (اخلاق، عربی)\nاز: شهید ثانی (966 ق)\nآداب تعلیم و تعلم را با استفاده از آیات و روایات و سخن بزرگان در یک مقدمه بیان نموده است.\nآغاز: «اعلم ان اللّه سبحانه...».\nهر دو نسخه در یک جلد آمده است.", $ref->entry_text);
        $this->assertSame('ش 2953، 122 برگ، 10×15، نسخ در 1252. تصحیح شده است.', $ref->copy_text);
        $this->assertSame("ش 584، 138 برگ، نسخ محمد بن علی در .1086\nآغازش افتادگی دارد.", Ref::where('manuscript_id', $other->id)->sole()->copy_text);
        $tail = Ref::where('manuscript_id', $last->id)->sole();
        $this->assertSame(["685- یوسف و زلیخا (فارسی)\nاز: جامی", 'ش 4134، 104 برگ، اول وآخر ناقص.'], [$tail->entry_text, $tail->copy_text]);
    }

    public function test_a_treatise_of_a_composite_volume_gets_its_part_and_the_volume_notes()
    {
        $second = $this->manuscript(['shelfmark' => '498/2']);
        $second->work->update(['primary_title' => 'اسرار الصلاة']);
        $first = $this->manuscript(['shelfmark' => '498/1']);
        $first->work->update(['primary_title' => 'منهاج الکرامة']);
        $path = $this->writePages([
            [25, "( 476 )\n\n1 - عمدة المراد «1 - 108» (نحو - عربی)\nاز: مازندرانی.\nهر دو نستعلیق.\n113 برگ، 15 × 20\n"],
            [26, "( 498 )\n\n1 - منهاج الکرامة «4 - 80» (امامت - عربی)\nاز: علامه حلی.\nچاپ شده است.\n\n2 - اسرار الصلاة = التنبیهات العلیة «80 - 131» (عربی)\nاز: شهید ثانی.\nچاپ شده است.\nکتاب اول نسخ ابوالقاسم 1092 با مهر بیضوی «عبده\nالراجی حسین 1237».\nوکتاب دوم تحریر سده 11 بدون نام کاتب.\n131 برگ، 12 × 19\n\n( 500 )\n\n1 - تقاسیم العلل «6 - 173» (طب - عربی)\nاز: رازی.\n"],
        ]);

        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $path])->assertSuccessful();
        unlink($path);

        $notes = "( 498 )\nکتاب اول نسخ ابوالقاسم 1092 با مهر بیضوی «عبده\nالراجی حسین 1237».\nوکتاب دوم تحریر سده 11 بدون نام کاتب.\n131 برگ، 12 × 19";
        $ref = Ref::where('manuscript_id', $second->id)->sole();
        $this->assertSame(["2 - اسرار الصلاة = التنبیهات العلیة «80 - 131» (عربی)\nاز: شهید ثانی.\nچاپ شده است.", $notes], [$ref->entry_text, $ref->copy_text]);
        $ref = Ref::where('manuscript_id', $first->id)->sole();
        $this->assertSame(["1 - منهاج الکرامة «4 - 80» (امامت - عربی)\nاز: علامه حلی.\nچاپ شده است.", $notes], [$ref->entry_text, $ref->copy_text]);
    }

    public function test_the_texts_pass_fills_reviewed_references_at_their_address_and_changes_nothing_else()
    {
        $ms = $this->manuscript(['shelfmark' => '1939']);
        $reviewer = User::factory()->create();
        $ref = Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'page_end' => 415,
            'shelfmark_page' => 415, 'entry_number' => 606, 'source' => Ref::SOURCE_AI_MATCH, 'match_method' => Matcher::METHOD_SINGLE_PAGE, 'confidence' => 40,
            'status' => Ref::STATUS_VERIFIED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()->subDay()->startOfSecond()]);
        $before = $ref->fresh()->toArray();
        $pages = $this->pages();

        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages, '--texts' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($ref->fresh()->entry_text);
        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages, '--texts' => true])->assertSuccessful();
        unlink($pages);

        $after = $ref->fresh();
        // the entry of 606 runs over two pages; 1939 also stands under 608, which is not this reference's address
        $this->assertSame("606- دیوان قاسمی (فارسی)\nاز: قاسمی\nآغاز: «ای دل و جان عاشقان»\nانجام: «صلوات خدای»", $after->entry_text);
        $this->assertSame('ش ۱۹۳۹، ۲۰۷ برگ، نستعلیق', $after->copy_text);
        $this->assertSame(Arr::except($before, ['entry_text', 'copy_text']), Arr::except($after->toArray(), ['entry_text', 'copy_text']));
        $this->assertSame(1, Ref::count());
    }

    public function test_the_printed_text_comes_first_under_the_source_text_until_the_reference_is_rejected()
    {
        $this->edition->update(['edition_label' => '۱۳۸۸، به کوشش رضا استادی']);
        $ms = $this->manuscript(['raw_text' => 'متن فنخا برای این نسخه']);
        $ref = Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH,
            'confidence' => 90, 'entry_text' => "607- دیوان قدسی (فارسی)\nاز: قدسی", 'copy_text' => 'ش 1939، 207 برگ، نستعلیق. این نسخه جلد چهارم است.']);
        $note = 'این متن را هوشواره با تطبیق شماره‌ی نسخه از چاپ فهرست برداشته و هنوز به دست انسان بازبینی نشده است.';

        $html = $this->get(route('manuscripts.show', $ms))->assertOk()
            // one «متن مأخذ» heading: the library's printed catalogue first, then Fankha
            // both alike: the address, «دیدن برگه» and «رونوشت متن»
            ->assertSeeInOrder(['متن مأخذ', 'فهرست کتابخانه مسجد اعظم، چاپ ۱۳۸۸', $note, 'ج ۲، ص ۴۱۴', 'دیدن برگه', 'رونوشت متن', '۶۰۷- دیوان قدسی (فارسی)', 'ش ۱۹۳۹، ۲۰۷ برگ، نستعلیق. این نسخه جلد چهارم است.',
                'فهرستگان نسخه‌های خطی ایران (فنخا)', 'ج ۱، ص ۱، ردیف ۱', 'دیدن برگه', 'رونوشت متن', 'متن فنخا برای این نسخه'])
            ->getContent();
        $this->assertStringNotContainsString('scan-source', $html);
        $this->assertStringNotContainsString('504138', $html);

        // a printing without a year in its label is named by the catalogue alone
        $this->edition->update(['edition_label' => 'کتابخانه مسجد اعظم، به کوشش رضا استادی']);
        $this->get(route('manuscripts.show', $ms))->assertSee('فهرست کتابخانه مسجد اعظم')->assertDontSee('فهرست کتابخانه مسجد اعظم، چاپ');

        $ref->update(['status' => Ref::STATUS_VERIFIED]);
        $this->get(route('manuscripts.show', $ms))->assertSee('۶۰۷- دیوان قدسی')->assertDontSee($note);

        $ref->update(['status' => Ref::STATUS_REJECTED]);
        $this->get(route('manuscripts.show', $ms))->assertOk()->assertDontSee('۶۰۷- دیوان قدسی')->assertDontSee('فهرست کتابخانه مسجد اعظم')->assertSeeInOrder(['متن مأخذ', 'متن فنخا برای این نسخه']);

        // without Fankha's text the heading still stands over the printed catalogue's
        $ref->update(['status' => Ref::STATUS_VERIFIED]);
        $ms->update(['raw_text' => null]);
        $this->get(route('manuscripts.show', $ms))->assertSeeInOrder(['متن مأخذ', 'فهرست کتابخانه مسجد اعظم', '۶۰۷- دیوان قدسی'])->assertDontSee('متن فنخا');
    }

    public function test_the_manuscript_page_links_the_entry_page_with_the_review_badge()
    {
        $ms = $this->manuscript(['metadata' => ['catalog_citation' => '[ف مخ: 2 - 817]']]);
        $ref = Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'shelfmark_page' => 415, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90]);
        Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->fankhaVolume->id, 'raw_text' => '[ف مخ: 2 - 817]', 'volume_number' => 2, 'page_start' => 817, 'source' => Ref::SOURCE_FANKHA]);
        $note = 'این نشانی را هوشواره با تطبیق شماره‌ی نسخه استخراج کرده و هنوز به دست انسان بازبینی نشده است.';

        $this->get(route('manuscripts.show', $ms))->assertOk()
            // the library catalogue first, Fankha last, without repeating the volume title under it
            ->assertSeeInOrder(['در مآخذ نسخه‌شناسی', 'چاپ ۱۳۶۵', 'دیدن برگه در فنخا'])
            ->assertDontSee($this->fankhaVolume->title)->assertSee('فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم، چاپ ۱۳۶۵، رضا استادی، ج ۲، ص ۴۱۴')->assertSee('catalogs/masjid-azam-ostadi/v02/p0414.webp', false)
            ->assertSee('masjid-azam-ostadi')
            ->assertSee($note)
            ->assertSee('گزارش نشانی نادرست');

        $ref->update(['status' => Ref::STATUS_VERIFIED]);
        $this->get(route('manuscripts.show', $ms))->assertSee('ص ۴۱۴')->assertDontSee($note);

        $ref->update(['status' => Ref::STATUS_REJECTED]);
        $this->get(route('manuscripts.show', $ms))->assertDontSee('ص ۴۱۴')->assertSee($this->fankhaVolume->title);
    }

    public function test_reviewers_verify_and_reject_machine_matches_in_the_queue()
    {
        $admin = User::firstOrCreate(['email' => 'refs-admin@fahares.net'], ['name' => 'داور آزمایشی', 'password' => 'password123', 'role' => 'admin']);
        $admin->forceFill(['role' => 'admin'])->save();
        $user = User::firstOrCreate(['email' => 'refs-user@fahares.net'], ['name' => 'کاربر', 'password' => 'password123', 'role' => 'user']);
        $high = Ref::create(['manuscript_id' => $this->manuscript(['shelfmark' => '3002'])->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 7, 'shelfmark_page' => 8, 'source' => Ref::SOURCE_AI_MATCH, 'match_method' => 'shelfmark_title', 'confidence' => 95]);
        $low = Ref::create(['manuscript_id' => $this->manuscript(['shelfmark' => '2000'])->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 50, 'source' => Ref::SOURCE_AI_MATCH, 'match_method' => 'ambiguous', 'confidence' => 10, 'notes' => 'جاهای دیگر: ص 60']);

        $this->actingAs($user)->get(route('admin.catalog-references.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.catalog-references.verify', $high))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.catalog-references.index', ['volume' => $this->edition->id]))->assertOk()
            ->assertSee('۳۰۰۲')->assertSee('۲۰۰۰')->assertSee('شماره در ص ۸')->assertSee('جاهای دیگر')->assertDontSee('scan-source');
        $this->actingAs($admin)->get(route('admin.catalog-references.index', ['confidence' => 'low']))->assertOk()
            ->assertSee('۲۰۰۰')->assertDontSee('۳۰۰۲');

        $this->actingAs($admin)->post(route('admin.catalog-references.verify', $high))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.catalog-references.reject', $low))->assertRedirect();
        $this->assertSame([Ref::STATUS_VERIFIED, $admin->id], [$high->fresh()->status, $high->fresh()->reviewed_by]);
        $this->assertNotNull($high->fresh()->reviewed_at);
        $this->assertSame(Ref::STATUS_REJECTED, $low->fresh()->status);
        $this->actingAs($admin)->get(route('admin.catalog-references.index'))->assertOk()->assertDontSee('۳۰۰۲');
        $this->actingAs($admin)->get(route('admin.catalog-references.index', ['status' => 'verified']))->assertOk()->assertSee('۳۰۰۲')->assertSee('داور آزمایشی');

        // Fankha rows are not machine matches and cannot be reviewed here
        $fankha = Ref::create(['manuscript_id' => $high->manuscript_id, 'catalog_volume_id' => $this->fankhaVolume->id, 'source' => Ref::SOURCE_FANKHA]);
        $this->actingAs($admin)->post(route('admin.catalog-references.verify', $fankha))->assertNotFound();
    }

    public function test_reviewers_verify_title_matches_in_bulk_and_revert_the_batch()
    {
        $admin = $this->admin();
        $user = User::firstOrCreate(['email' => 'refs-user@fahares.net'], ['name' => 'کاربر', 'password' => 'password123', 'role' => 'user']);
        $ref = fn (string $shelfmark, string $method, int $confidence, array $extra = []) => Ref::create($extra + [
            'manuscript_id' => $this->manuscript(['shelfmark' => $shelfmark])->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2,
            'page_start' => (int) $shelfmark, 'source' => Ref::SOURCE_AI_MATCH, 'match_method' => $method, 'confidence' => $confidence]);
        $titles = collect(['4001', '4002', '4003'])->map(fn ($s) => $ref($s, 'shelfmark_title', 80));
        $single = $ref('4004', 'shelfmark_single_page', 59);
        $only = $ref('4005', 'shelfmark_only', 25);
        $done = $ref('4006', 'shelfmark_title', 90, ['status' => Ref::STATUS_REJECTED]);
        $filters = ['volume' => $this->edition->id, 'method' => 'shelfmark_title', 'confidence' => 'high'];

        // The queue offers the button only for title matches, with the count to confirm
        $this->actingAs($admin)->get(route('admin.catalog-references.index', ['volume' => $this->edition->id, 'method' => 'shelfmark_only']))->assertOk()
            ->assertSee('۴۰۰۵')->assertDontSee('۴۰۰۱')->assertDontSee('تأیید همه‌ی')->assertSee('پالایه‌ی پیشنهادی');
        $this->actingAs($admin)->get(route('admin.catalog-references.index', $filters))->assertOk()
            ->assertSee('تأیید همه‌ی ۳ نشانی این پالایه')->assertSee('۳ تطبیق تأیید شود؟', false);

        // Only reviewers, only title matches, and only the count the reviewer saw
        $this->actingAs($user)->post(route('admin.catalog-references.bulk-verify'), $filters + ['expected' => 3])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.catalog-references.bulk-verify'), ['method' => 'shelfmark_only', 'expected' => 1])->assertStatus(422);
        $this->actingAs($admin)->post(route('admin.catalog-references.bulk-verify'), ['method' => '', 'expected' => 5])->assertStatus(422);
        $this->actingAs($admin)->post(route('admin.catalog-references.bulk-verify'), $filters + ['expected' => 2])->assertSessionHas('error');
        $this->assertSame(0, Ref::where('status', Ref::STATUS_VERIFIED)->count());

        $this->actingAs($admin)->post(route('admin.catalog-references.bulk-verify'), $filters + ['expected' => 3])->assertSessionHas('success');
        foreach ($titles as $title) {
            $this->assertSame([Ref::STATUS_VERIFIED, $admin->id], [$title->fresh()->status, $title->fresh()->reviewed_by]);
        }
        $this->assertSame(1, $titles->map(fn ($t) => $t->fresh()->reviewed_at->toDateTimeString())->unique()->count());
        $this->assertSame([Ref::STATUS_UNVERIFIED, Ref::STATUS_UNVERIFIED, Ref::STATUS_REJECTED], [$single->fresh()->status, $only->fresh()->status, $done->fresh()->status]);

        $batch = AuditLog::where('action', Ref::ACTION_BULK_VERIFY)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $batch->user_id);
        $this->assertSame($titles->pluck('id')->all(), $batch->old_values['ids']);
        $this->assertSame([3, $filters], [$batch->new_values['count'], $batch->new_values['filters']]);
        $this->actingAs($admin)->get(route('admin.catalog-references.index'))->assertOk()->assertSee('آخرین تأیید گروهی')->assertSee('برگرداندن این دسته');

        // A row rejected by hand since keeps its review; the rest return to the queue, once only
        $titles[2]->fresh()->review($admin, Ref::STATUS_REJECTED);
        $this->travel(5)->seconds();
        $this->actingAs($admin)->post(route('admin.catalog-references.bulk-revert', $batch))->assertSessionHas('success');
        $this->assertSame([Ref::STATUS_UNVERIFIED, null, null], [$titles[0]->fresh()->status, $titles[0]->fresh()->reviewed_by, $titles[0]->fresh()->reviewed_at]);
        $this->assertSame(Ref::STATUS_UNVERIFIED, $titles[1]->fresh()->status);
        $this->assertSame(Ref::STATUS_REJECTED, $titles[2]->fresh()->status);
        $this->assertSame(['reverted' => 2, 'kept' => 1], AuditLog::where('action', Ref::ACTION_BULK_REVERT)->where('auditable_id', $batch->id)->sole()->new_values);
        $this->actingAs($admin)->post(route('admin.catalog-references.bulk-revert', $batch))->assertSessionHas('error');
        $this->assertNull(Ref::lastBulkVerify());
        $this->actingAs($admin)->get(route('admin.catalog-references.index'))->assertOk()->assertDontSee('آخرین تأیید گروهی');

        // The artisan command reverts the latest batch the same way
        $this->actingAs($admin)->post(route('admin.catalog-references.bulk-verify'), $filters + ['expected' => 2])->assertSessionHas('success');
        $this->assertSame(Ref::STATUS_VERIFIED, $titles[0]->fresh()->status);
        $this->artisan('catalogs:revert-bulk-verify', ['--force' => true])->expectsOutputToContain('Reverted 2; kept 0')->assertSuccessful();
        $this->assertSame(Ref::STATUS_UNVERIFIED, $titles[0]->fresh()->status);
        $this->artisan('catalogs:revert-bulk-verify', ['--force' => true])->assertFailed();
    }

    private function admin(): User
    {
        $admin = User::firstOrCreate(['email' => 'refs-admin@fahares.net'], ['name' => 'داور آزمایشی', 'password' => 'password123', 'role' => 'admin']);
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    private function suggestAddress(Manuscript $ms, string $field, string $value)
    {
        return $this->postJson('/suggestions', ['type' => 'manuscript', 'id' => $ms->id, 'kind' => 'field', 'field_name' => $field,
            'suggested_value' => $value, 'rationale_citation' => 'برگه‌ی چاپی فهرست را دیدم']);
    }

    private function approveLatest(Manuscript $ms, array $data = [])
    {
        $s = FieldSuggestion::where('suggestable_id', $ms->id)->where('status', 'pending')->latest('id')->firstOrFail();

        return $this->actingAs($this->admin())->post("/admin/suggestions/{$s->id}/approve", $data + ['applied_value' => $s->suggested_value, 'outcome' => 'adopt']);
    }

    public function test_address_pencils_appear_beside_each_source_in_suggestion_mode()
    {
        $ms = $this->manuscript();
        $ref = Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90]);

        $this->get(route('manuscripts.show', $ms))->assertOk()
            ->assertSee("field: 'fankha_page'", false)
            ->assertSee("field: 'catalog_reference_{$ref->id}'", false)
            ->assertSee('نشانی در فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم، چاپ ۱۳۶۵، رضا استادی')
            ->assertSee('ج ۲، ص ۴۱۴');
    }

    public function test_an_approved_address_corrects_the_reference_and_the_matcher_keeps_it()
    {
        $ms = $this->manuscript(['shelfmark' => '1939']);
        $ms->work->update(['primary_title' => 'دیوان قاسمی']);
        $pages = $this->pages();
        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages])->assertSuccessful();
        $ref = Ref::where('manuscript_id', $ms->id)->sole();
        $this->assertSame(414, $ref->page_start);

        // another copy's reference cannot be corrected through this one
        $other = $this->manuscript(['shelfmark' => '1']);
        $this->suggestAddress($other, "catalog_reference_{$ref->id}", 'ج ۲، ص ۴۱۶')->assertStatus(422);
        $this->suggestAddress($ms, "catalog_reference_{$ref->id}", 'ص ۴۱۶')->assertStatus(422);

        $this->suggestAddress($ms, "catalog_reference_{$ref->id}", 'ج 2، ص 416')->assertOk();
        $this->assertSame('ج ۲، ص ۴۱۶', FieldSuggestion::latest('id')->first()->suggested_value);
        $this->assertSame('ج ۲، ص ۴۱۴', FieldSuggestion::latest('id')->first()->current_value);
        $this->approveLatest($ms)->assertSessionHasNoErrors();

        $ref->refresh();
        $this->assertSame([416, Ref::SOURCE_MANUAL, Ref::STATUS_VERIFIED, $this->admin()->id], [$ref->page_start, $ref->source, $ref->status, $ref->reviewed_by]);
        $this->assertSame([1, 1, 1], [$ms->fresh()->volume_number, $ms->fresh()->page_start, $ms->fresh()->sequence_number], 'the Fankha columns are untouched');
        $this->get(route('manuscripts.show', $ms))->assertSee('ج ۲، ص ۴۱۶')->assertDontSee('هنوز به دست انسان بازبینی نشده');

        $this->artisan('catalogs:match-edition', ['edition' => 'masjid-azam-ostadi', 'pages' => $pages])->assertSuccessful();
        $this->assertSame([416], Ref::where('manuscript_id', $ms->id)->pluck('page_start')->all());

        // the rollback of the approval puts the machine match back
        $this->actingAs($this->admin())->post('/admin/audit-logs/' . AuditLog::where('action', 'verify')->latest('id')->value('id') . '/rollback')->assertSessionHas('success');
        $this->assertSame([414, Ref::SOURCE_AI_MATCH, Ref::STATUS_UNVERIFIED], [$ref->fresh()->page_start, $ref->fresh()->source, $ref->fresh()->status]);
        unlink($pages);
    }

    public function test_a_copy_reported_absent_loses_the_link_and_fankha_addresses_are_applied_to_the_copy()
    {
        $ms = $this->manuscript();
        $ref = Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 30]);

        $this->suggestAddress($ms, "catalog_reference_{$ref->id}", 'نیست')->assertOk();
        $this->approveLatest($ms)->assertSessionHasNoErrors();
        $this->assertSame(Ref::STATUS_REJECTED, $ref->fresh()->status);
        $this->get(route('manuscripts.show', $ms))->assertDontSee('masjid-azam-ostadi/v02/p0414.webp', false);

        $this->suggestAddress($ms, 'fankha_page', 'نیست')->assertStatus(422);
        $this->suggestAddress($ms, 'fankha_page', 'ج ۳، ص ۱۲، ردیف ۴')->assertOk();
        $this->assertSame('ج ۱، ص ۱، ردیف ۱', FieldSuggestion::latest('id')->first()->current_value);
        $this->approveLatest($ms)->assertSessionHasNoErrors();
        $this->assertSame([3, 12, 4], [$ms->fresh()->volume_number, $ms->fresh()->page_start, $ms->fresh()->sequence_number]);
    }

    public function test_bibliography_citations_are_rewritten_in_their_own_form()
    {
        $old = ['catalog' => 'dharia', 'volume' => 25, 'page' => 101];
        $rewrite = fn (array $entries, int $v, int $p, array $o = null) => \App\Support\BibliographyLinks::rewriteCitation($entries, $o ?? $old, $v, $p);

        $this->assertSame(['منزوی 977/2', 'الذریعه 102/25'], $rewrite(['منزوی 977/2', 'الذریعه 101/25'], 25, 102));
        $this->assertSame(['الذریعه 50/24'], $rewrite(['الذریعه 101/25'], 24, 50));
        $this->assertSame(['الذریعة ج 25، ص 102'], $rewrite(['الذریعة ج 25، ص 101'], 25, 102));
        $joined = ['catalog' => 'dharia', 'volume' => 24, 'page' => 131];
        $this->assertSame(['الذریعه 130/24 و 132'], $rewrite(['الذریعه 130/24 و 131'], 24, 132, $joined));
        $this->assertNull($rewrite(['الذریعه 130/24 و 131'], 25, 132, $joined), 'a bare page cannot change volume');
        $this->assertNull($rewrite(['منزوی 977/2'], 25, 102), 'the page is not cited');
    }

    public function test_work_source_addresses_are_suggested_and_applied()
    {
        $work = Work::create(['primary_title' => 'اثر مآخذدار', 'clean_title' => 'اثر مآخذدار', 'slug' => 'work-bib-' . uniqid(),
            'volume_number' => 3, 'page_start' => 10, 'page_end' => 11, 'bibliography' => ['منزوی 977/2', 'الذریعه 101/25']]);
        $suggest = fn (string $field, string $value) => $this->postJson('/suggestions', ['type' => 'work', 'id' => $work->id, 'kind' => 'field',
            'field_name' => $field, 'suggested_value' => $value, 'rationale_citation' => 'برگه‌ی چاپی را دیدم']);
        $approve = function () use ($work) {
            $s = FieldSuggestion::where('suggestable_type', Work::class)->where('suggestable_id', $work->id)->where('status', 'pending')->latest('id')->firstOrFail();

            return $this->actingAs($this->admin())->post("/admin/suggestions/{$s->id}/approve", ['applied_value' => $s->suggested_value, 'outcome' => 'adopt']);
        };

        $this->get(route('works.show', $work))->assertOk()
            ->assertSee("field: 'bibliography_page_dharia_25_101'", false)->assertSee("field: 'fankha_page'", false);

        $suggest('bibliography_page_dharia_25_99', 'ج ۲۵، ص ۱۰۲')->assertStatus(422);
        $suggest('bibliography_page_dharia_25_101', 'ج ۲۵، ص ۱۰۲')->assertOk();
        $this->assertSame(['ج ۲۵، ص ۱۰۱', 'ج ۲۵، ص ۱۰۲'], [FieldSuggestion::latest('id')->first()->current_value, FieldSuggestion::latest('id')->first()->suggested_value]);
        $approve()->assertSessionHasNoErrors();
        $this->assertSame(['منزوی 977/2', 'الذریعه 102/25'], $work->fresh()->bibliography);

        $suggest('fankha_page', 'ج ۴، ص ۲۰')->assertOk();
        $approve()->assertSessionHasNoErrors();
        $this->assertSame([4, 20, 21], [$work->fresh()->volume_number, $work->fresh()->page_start, $work->fresh()->page_end]);

        // the rollback restores the bibliography text
        $this->actingAs($this->admin())->post('/admin/audit-logs/' . AuditLog::where('action', 'verify')->where('auditable_id', $work->id)->orderBy('id')->value('id') . '/rollback')->assertSessionHas('success');
        $this->assertSame(['منزوی 977/2', 'الذریعه 101/25'], $work->fresh()->bibliography);
    }
}
