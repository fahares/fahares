<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use App\Support\SourceReference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The citation of a printed source, one of its pages or one entry (step 14.389, backlog item 132), and the work and
 * copy pages without a citation window of their own (step 14.390, backlog item 133).
 */
class CiteTest extends TestCase
{
    private Source $source;

    private SourceVolume $volume;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = Source::create(['code' => 'monzavi', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'فهرستوارۀ کتاب‌های فارسی',
            'short_name' => 'فهرستواره منزوی', 'compiler' => 'احمد منزوی', 'publication_place' => 'تهران', 'publisher' => 'مرکز دائرة‌المعارف بزرگ اسلامی',
            'publication_year' => '1382', 'unit' => 'صفحه', 'volumes_count' => 11, 'metadata' => ['images' => false]]);
        $this->volume = SourceVolume::create(['source_id' => $this->source->id, 'volume_number' => 5, 'facsimile_code' => 'monzavi']);
        DB::table('source_pages')->insert(['source_volume_id' => $this->volume->id, 'page' => 3366, 'text' => 'متن صفحۀ آزمایشی']);
    }

    private function item(array $columns = []): SourceItem
    {
        return SourceItem::create($columns + ['source_id' => $this->source->id, 'source_volume_id' => $this->volume->id, 'volume_number' => 5,
            'item_key' => '1442', 'position' => 1, 'kind' => 'entry', 'page_start' => 3366, 'page_end' => 3367, 'entry_number' => 1442,
            'title' => 'تحفة الامیر', 'title_normalized' => 'تحفة الامیر', 'text' => 'تحفة الامیر. متن مدخل.', 'text_status' => 'auto']);
    }

    public function test_a_page_is_cited_in_every_form_from_the_same_parts()
    {
        $ref = new SourceReference($this->source, $this->volume, 3366);
        $url = route('source-pages.show', ['monzavi', 5, 3366]);

        $this->assertSame('احمد منزوی، فهرستوارۀ کتاب‌های فارسی، ج ۵، تهران: مرکز دائرة‌المعارف بزرگ اسلامی، ۱۳۸۲، ص ۳۳۶۶.', $ref->printed());
        $this->assertSame([], $ref->missing());
        $this->assertSame('(فهرستواره منزوی، ج ۵، ص ۳۳۶۶)', $ref->inText());
        $this->assertSame('احمد منزوی. (۱۳۸۲). فهرستوارۀ کتاب‌های فارسی (ج ۵، ص ۳۳۶۶). تهران: مرکز دائرة‌المعارف بزرگ اسلامی.', $ref->apa());
        $this->assertSame('احمد منزوی. فهرستوارۀ کتاب‌های فارسی. ج ۵. تهران: مرکز دائرة‌المعارف بزرگ اسلامی، ۱۳۸۲. ص ۳۳۶۶.', $ref->chicago());
        // the address keeps its Western digits; the date seen is Jalali
        $this->assertSame('احمد منزوی، فهرستوارۀ کتاب‌های فارسی، ج ۵، ص ۳۳۶۶، فهارس، ' . $url . '، دیده‌شده در ۱۴۰۵/۰۹/۰۴.',
            $ref->fahares(Carbon::parse('2026-11-25 12:00', 'UTC')));

        $bib = $ref->bibtex(Carbon::parse('2026-11-25'));
        $this->assertStringStartsWith('@book{fahares-monzavi-5-3366,', $bib);
        $this->assertStringContainsString('  author = {احمد منزوی}', $bib);
        $this->assertStringContainsString('  volume = {5}', $bib);
        $this->assertStringContainsString('  pages = {3366}', $bib);
        $this->assertStringContainsString('  year = {1382}', $bib);
        $this->assertStringContainsString('  address = {تهران}', $bib);
        $this->assertStringContainsString('  url = {' . $url . '}', $bib);

        $ris = $ref->ris(Carbon::parse('2026-11-25'));
        $this->assertStringStartsWith("TY  - BOOK\r\nAU  - احمد منزوی\r\nTI  - فهرستوارۀ کتاب‌های فارسی\r\n", $ris);
        $this->assertStringContainsString("SP  - 3366\r\n", $ris);
        $this->assertStringEndsWith("ER  - \r\n", $ris);
    }

    public function test_an_entry_is_cited_by_its_pages_and_number_and_one_without_a_number_by_its_heading()
    {
        $ref = SourceReference::forItem($this->item());
        $this->assertSame('(فهرستواره منزوی، ج ۵، ص ۳۳۶۶–۳۳۶۷، ش ۱۴۴۲)', $ref->inText());
        $this->assertSame(route('source-items.show', ['monzavi', 5, '1442']), $ref->url());
        $this->assertStringContainsString('  pages = {3366--3367}', $ref->bibtex());
        $this->assertStringContainsString("M1  - 1442\r\n", $ref->ris());

        $unnumbered = SourceReference::forItem($this->item(['item_key' => '1442/1', 'position' => 2, 'entry_number' => null, 'page_end' => 3366, 'title' => 'ذیل آن']));
        $this->assertSame('(فهرستواره منزوی، ج ۵، ص ۳۳۶۶، ذیل «ذیل آن»)', $unnumbered->inText());
    }

    public function test_what_the_site_has_not_recorded_is_left_out_and_named_never_a_dash()
    {
        $this->source->update(['compiler' => null, 'publication_place' => null, 'publisher' => null, 'publication_year' => null]);
        $ref = new SourceReference($this->source->fresh(), $this->volume, 3366);

        $this->assertSame('فهرستوارۀ کتاب‌های فارسی، ج ۵، ص ۳۳۶۶.', $ref->printed());
        $this->assertSame(['پدیدآور', 'محل نشر', 'ناشر', 'سال چاپ'], $ref->missing());
        $this->assertSame('(بی‌تا). فهرستوارۀ کتاب‌های فارسی (ج ۵، ص ۳۳۶۶).', $ref->apa());
        $this->assertStringNotContainsString(' - ', $ref->printed() . $ref->chicago());

        $this->get('/cite?source=monzavi&volume=5&page=3366')->assertOk()
            ->assertSee('پدیدآور، محل نشر، ناشر و سال چاپ این منبع در فهارس ثبت نشده است', false);
    }

    public function test_a_library_catalogue_is_made_by_the_catalogers_of_its_volume_and_its_volume_imprint_comes_first()
    {
        $catalogue = Source::create(['code' => 'masjid-test', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10,
            'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', 'publisher' => 'ناشر کتاب', 'unit' => 'صفحه', 'volumes_count' => 2]);
        $cv = CatalogVolume::create(['title' => $catalogue->title . ' - جلد 2', 'volume_number' => 2]);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], ['source_id' => $catalogue->id, 'volume_number' => 2,
            'publisher' => 'ناشر جلد', 'publication_place' => 'قم', 'publication_year' => '1388', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['رضا استادی', 'cataloger'], ['محمد حسین', 'cataloger'], ['سید محمود مرعشی', 'supervisor']] as [$name, $role]) {
            $person = Cataloger::create(['name' => $name, 'slug' => 'c-' . uniqid()]);
            DB::table('catalog_volume_cataloger')->insert(['catalog_volume_id' => $cv->id, 'cataloger_id' => $person->id, 'role' => $role]);
        }

        $ref = new SourceReference($catalogue, SourceVolume::findOrFail($cv->id), 414);
        $this->assertSame('رضا استادی و محمد حسین، زیر نظر سید محمود مرعشی', $ref->authors());
        $this->assertSame('رضا استادی و محمد حسین، زیر نظر سید محمود مرعشی، فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم، ج ۲، قم: ناشر جلد، ۱۳۸۸، ص ۴۱۴.', $ref->printed());
        // a volume the registry marks never printed says so, and no year is asked for
        DB::table('source_volumes')->where('id', $cv->id)->update(['publication_year' => Source::UNPUBLISHED, 'publication_place' => null]);
        $unprinted = new SourceReference($catalogue, SourceVolume::findOrFail($cv->id), 414);
        $this->assertStringEndsWith('ج ۲، ناشر جلد، چاپ‌نشده، ص ۴۱۴.', $unprinted->printed());
        $this->assertSame([], $unprinted->missing());
        // a page the site has no text of stands for the source's own page
        $this->assertSame(route('sources.show', 'masjid-test'), $ref->url());
    }

    public function test_the_citation_page_serves_a_page_an_entry_and_the_book_with_the_downloads()
    {
        $this->item();

        $this->get('/cite?source=monzavi&volume=5&page=3366')->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('ارجاع به این صفحه', false)
            ->assertSeeInOrder(['ارجاع به چاپ منبع', 'ارجاع به فهارس', 'ارجاع درون‌متنی', 'APA', 'شیکاگو', 'نشانی پایدار'], false)
            ->assertSee('احمد منزوی، فهرستوارۀ کتاب‌های فارسی، ج ۵، تهران: مرکز دائرة‌المعارف بزرگ اسلامی، ۱۳۸۲، ص ۳۳۶۶.', false)
            ->assertDontSee('data-cite-missing', false)
            ->assertSee(e(route('cite.show', ['source' => 'monzavi', 'volume' => 5, 'page' => 3366, 'format' => 'bib'])), false);

        $this->get('/cite?source=monzavi&volume=5&entry=1442')->assertOk()
            ->assertSee('ارجاع به این مدخل', false)->assertSee('مدخل «تحفة الامیر»', false)
            ->assertSee('(فهرستواره منزوی، ج ۵، ص ۳۳۶۶–۳۳۶۷، ش ۱۴۴۲)', false);
        $this->get('/cite?source=monzavi')->assertOk()->assertSee('ارجاع به این کتاب', false)
            ->assertSee('احمد منزوی، فهرستوارۀ کتاب‌های فارسی، تهران: مرکز دائرة‌المعارف بزرگ اسلامی، ۱۳۸۲.', false);
        // the page viewer names the volume by its facsimile code
        $this->get('/cite?catalog=monzavi&volume=5&page=3366')->assertOk()->assertSee('ص ۳۳۶۶', false);

        $this->get('/cite?source=monzavi&volume=5&page=3366&format=bib')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="fahares-monzavi-5-3366.bib"');
        $this->get('/cite?source=monzavi&volume=5&entry=1442&format=ris')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="fahares-monzavi-5-1442.ris"');

        $this->get('/cite?source=nothing')->assertNotFound();
        $this->get('/cite?source=monzavi&volume=9&page=1')->assertNotFound();
        $this->get('/cite?source=monzavi&volume=5&page=4000')->assertNotFound();
        $this->get('/cite?source=monzavi&volume=5&entry=9999')->assertNotFound();
        $this->get('/cite?catalog=nothing&volume=5&page=1')->assertNotFound();
    }

    public function test_the_source_text_entry_and_source_pages_link_their_citation_and_copy_a_selection_with_it()
    {
        $this->item();

        $this->get('/sources/monzavi/5/page/3366')->assertOk()
            ->assertSee('href="' . e(route('cite.show', ['source' => 'monzavi', 'volume' => 5, 'page' => 3366])) . '"', false)
            ->assertSee('citeQuote(', false)->assertSee('(فهرستواره منزوی، ج ۵، ص ۳۳۶۶)', false);
        $this->get(route('source-items.show', ['monzavi', 5, '1442']))->assertOk()
            ->assertSee('href="' . e(route('cite.show', ['source' => 'monzavi', 'volume' => 5, 'entry' => '1442'])) . '"', false)
            ->assertSee('ارجاع به این مدخل', false)->assertSee('citeQuote(', false);
        $this->get(route('sources.show', 'monzavi'))->assertOk()
            ->assertSee('href="' . route('cite.show', ['source' => 'monzavi']) . '"', false)->assertSee('ارجاع به این کتاب', false)
            ->assertSee('تهران', false);
    }

    public function test_the_work_page_has_no_citation_window_of_its_own()
    {
        $work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'kafi-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        $this->get(route('works.show', $work))->assertOk()
            ->assertDontSee('citationModalOpen', false)->assertDontSee('دریافت استناد علمی', false)
            // the short permanent address stays beside the record's id
            ->assertSee(url('/w/' . $work->id), false);
    }

    public function test_the_admin_saves_the_imprint_and_leaves_it_when_a_form_does_not_send_it()
    {
        $admin = User::create(['name' => 'مدیر', 'email' => 'cite-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $base = ['title' => $this->source->title, 'kind' => $this->source->kind, 'rank' => 20];

        $this->actingAs($admin)->put(route('admin.sources.update', $this->source), $base + ['compiler' => ' آقا بزرگ  تهرانی ',
            'edition_label' => 'دوم', 'publication_place' => 'بیروت', 'publisher' => 'دار الاضواء', 'publication_year' => '1403', 'short_name' => 'منزوی'])
            ->assertRedirect(route('admin.sources.show', $this->source));
        $this->source->refresh();
        $this->assertSame(['آقا بزرگ تهرانی', 'دوم', 'بیروت', 'دار الاضواء', '1403'],
            [$this->source->compiler, $this->source->edition_label, $this->source->publication_place, $this->source->publisher, $this->source->publication_year]);

        $this->actingAs($admin)->put(route('admin.sources.update', $this->source), $base + ['short_name' => 'منزوی'])->assertRedirect();
        $this->assertSame('بیروت', $this->source->fresh()->publication_place);
        $this->actingAs($admin)->get(route('admin.sources.show', $this->source))->assertOk()->assertSee('name="publication_place"', false);
    }
}
