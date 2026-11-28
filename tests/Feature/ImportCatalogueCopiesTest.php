<?php

namespace Tests\Feature;

use App\Models\CatalogVolume;
use App\Models\Cataloger;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * catalogs:import-copies (backlog item 158, step 14.447): the copies a printed catalogue describes and Fankha never
 * cites, made with their library and volume from a data file. The entries are those of «فهرست نسخه‌های خطی کتابخانۀ
 * شخصی سید صادق حسینی اشکوری»، دفتر ۱: no. 2 under a work the site has, no. 324 a collection whose treatises go under
 * new works, one of them by a person the site has.
 */
class ImportCatalogueCopiesTest extends TestCase
{
    private string $file;

    private Work $riaz;

    private Person $attar;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->riaz = Work::create(['primary_title' => 'انوار الرياض فی شرح الرياض', 'clean_title' => 'انوار الرياض فی شرح الرياض',
            'slug' => 'icc-riaz-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->attar = Person::create(['name' => 'عطار، محمد بن ابراهیم', 'normalized_name' => 'عطار، محمد بن ابراهیم', 'slug' => 'icc-attar-' . uniqid(), 'death_year_hijri' => 627]);
        Cataloger::create(['name' => 'یوسف بیگ باباپور آزمون', 'slug' => 'icc-cataloger-' . uniqid()]);
        $this->file = storage_path('framework/testing/icc-' . uniqid() . '.json');
        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, json_encode($this->data(), JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        File::delete($this->file);
        parent::tearDown();
    }

    private function data(): array
    {
        $collection = ['collection_key' => '324', 'entry_page' => 431, 'page_end' => 434, 'folios' => 130, 'lines' => 22, 'dimensions' => 'وزیری',
            'binding' => 'جلد تیماج زرشکی ترنجی و ربع ترنج در چهار گوشه .', 'script_names' => 'نستعلیق', 'scribe_name' => 'عبد الله',
            'copy_date_raw' => '۴ محرم ۱۳۳۰ ق و ۳ جمادی الاوّل ۱۳۲۹ ق',
            'copy_text' => "* نستعلیق، عبد الله، ۴ محرم ۱۳۳۰ ق و ۳ جمادی الاوّل ۱۳۲۹ ق، عناوین شنگرف .\nجلد تیماج زرشکی ترنجی و ربع ترنج در چهار گوشه .\n۱۳۰ گ، ۲۲ س، وزیری"];

        return [
            'library' => ['slug' => 'icc-eshkevari-' . uniqid(), 'name' => 'سید صادق حسینی اشکوری آزمون', 'city' => 'قم', 'country' => 'ایران',
                'full_name' => 'کتابخانۀ شخصی سید صادق حسینی اشکوری'],
            'volume' => ['title' => 'فهرست نسخه‌های خطی کتابخانۀ شخصی سید صادق حسینی اشکوری - دفتر اول', 'volume_number' => 1,
                'facsimile_code' => 'icc-eshkevari', 'page_first' => 1, 'page_last' => 480, 'pages_count' => 480,
                'publisher' => 'مجمع ذخائر اسلامی', 'publication_year' => '۱۳۹۰', 'manuscripts_range' => '۱-۳۴۰',
                'catalogers' => [['slug' => Cataloger::latest('id')->value('slug'), 'role' => 'cataloger']]],
            'copies' => [
                ['shelfmark' => '2', 'page' => 73, 'entry_page' => 73, 'page_end' => 74, 'folios' => 287, 'lines' => 27, 'dimensions' => 'رحلی کوچک',
                    'binding' => 'جلد تیماج عنّابی حاشیه گرهی .', 'script_names' => 'نسخ', 'scribe_name' => 'محمّد رضا بن محمّد مهدی خوزانی',
                    'copy_date_raw' => '۱۲۸۸ ـ ۱۲۸۹ ق', 'incipit_text' => 'بسمله، حمدله، و بعد فیقول المفتقر الی العفو الرّ بانی محمّد بن عبد الصمد الحسینی . . .',
                    'entry_text' => "انوار الریاض = شرح ریاض المسائل (فقه ـ عربی)\nاز: سیّد محمّد بن عبد الصمد حسینی شهشهانی اصفهانی (۱۲۸۷ ق)",
                    'copy_text' => "* نسخ، محمّد رضا بن محمّد مهدی خوزانی، ۱۲۸۸ ـ ۱۲۸۹ ق .\nجلد تیماج عنّابی حاشیه گرهی .\n۲۸۷ گ، ۲۷ س، رحلی کوچک",
                    'work' => ['slug' => $this->riaz->slug, 'method' => 'words_author']],
                ['shelfmark' => '324/1', 'page' => 431, 'range' => '۲پ تا ۹ر', ...$collection,
                    'entry_text' => '۱ ـ فرهنگ مشتقات فعلی (۱) «۲ پ - ۹ ر» (لغتـ فارسی)',
                    'work' => ['title' => 'فرهنگ مشتقات فعلی (۱)', 'subject' => 'لغت', 'language' => 'فارسی', 'method' => 'no_title']],
                ['shelfmark' => '324/6', 'page' => 433, 'range' => '۳۷ر تا ۵۷ر', ...$collection,
                    'entry_text' => '۶ ـ پندنامه = پسرنامه = ولدنامه «۳۷ ر ـ ۵۷ ر» (شعر ـ فارسی)',
                    'work' => ['title' => 'پندنامه = پسرنامه = ولدنامه', 'author_name' => 'فرید الدین محمّد بن ابراهیم عطار نیشابوری (۶۲۷ ق)',
                        'author_slug' => $this->attar->slug, 'subject' => 'شعر', 'language' => 'عربی و فارسی', 'method' => 'no_title']],
            ],
        ];
    }

    private function library(): ?Library
    {
        return Library::where('full_name', 'کتابخانۀ شخصی سید صادق حسینی اشکوری')->where('name', 'سید صادق حسینی اشکوری آزمون')->first();
    }

    public function test_the_library_volume_and_copies_are_made_once()
    {
        $this->artisan('catalogs:import-copies', ['file' => $this->file])->assertSuccessful()
            ->expectsOutputToContain('3 copies made (2 under new works), 0 unchanged.');

        $library = $this->library();
        $this->assertSame(3, $library->manuscripts_count);
        $volume = CatalogVolume::where('library_id', $library->id)->where('facsimile_code', 'icc-eshkevari')->firstOrFail();
        $this->assertSame('مجمع ذخائر اسلامی', $volume->publisher);
        $this->assertSame(1, $volume->catalogers()->count());
        // the volume is a volume of the source registry too, under the same id
        $this->assertTrue(DB::table('source_volumes')->where('id', $volume->id)->exists());

        $two = Manuscript::where('library_id', $library->id)->where('shelfmark', '2')->firstOrFail();
        $this->assertSame($this->riaz->id, $two->work_id);
        $this->assertSame('محمّد رضا بن محمّد مهدی خوزانی', $two->scribe_name);
        $this->assertSame(287, $two->folios);
        $this->assertSame(Manuscript::PROVENANCE_CATALOGUE, $two->metadata['provenance']['kind']);
        $this->assertSame(1, $this->riaz->fresh()->manuscripts_count);

        // its place in the catalogue: the catalogue's own entry, the page it was made from, so verified and without ⓘ;
        // with the entry's text for the page's links
        $entry = SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $two->id)->firstOrFail();
        $this->assertSame([SourceEntry::ORIGIN_CATALOGUE, SourceEntry::STATUS_VERIFIED, 73, 74, SourceEntry::TEXT_AUTO],
            [$entry->origin, $entry->status, $entry->page_start, $entry->page_end, $entry->text_status]);
        $this->assertFalse($entry->isAwaitingReview());
        $this->assertSame($volume->id, $entry->source_volume_id);
        $this->assertStringStartsWith('* نسخ، محمّد رضا', $entry->copy_text);

        // the collection's treatises: one volume, ranges, new works with their subject, language and author
        $treatise = Manuscript::where('library_id', $library->id)->where('shelfmark', '324/6')->firstOrFail();
        $this->assertSame('324', $treatise->collection_key);
        $this->assertSame([37, 57], [$treatise->range_from_number, $treatise->range_to_number]);
        $work = $treatise->work;
        $this->assertSame('پندنامه = پسرنامه = ولدنامه', $work->primary_title);
        $this->assertSame($this->attar->id, $work->author_id);
        $this->assertSame(['شعر', 'عربی، فارسی'], [$work->subject_summary, $work->language_summary]);

        // a second run changes nothing
        $this->artisan('catalogs:import-copies', ['file' => $this->file])->assertSuccessful()
            ->expectsOutputToContain('0 copies made (0 under new works), 3 unchanged.');
        $this->assertSame(3, Manuscript::where('library_id', $library->id)->count());
    }

    public function test_a_run_again_settles_the_entries_step_14_447_left_awaiting_review_but_not_a_reviewed_one()
    {
        $this->artisan('catalogs:import-copies', ['file' => $this->file])->assertSuccessful();
        $ids = Manuscript::where('library_id', $this->library()->id)->pluck('id');
        $entries = SourceEntry::where('citable_type', Manuscript::class)->whereIn('citable_id', $ids);
        // as the first version wrote them; one of them since rejected by a reviewer
        (clone $entries)->update(['origin' => SourceEntry::ORIGIN_MATCH, 'status' => SourceEntry::STATUS_UNVERIFIED]);
        $rejected = (clone $entries)->orderBy('id')->first();
        $rejected->update(['status' => SourceEntry::STATUS_REJECTED, 'reviewed_by' => null, 'reviewed_at' => now()]);

        $this->artisan('catalogs:import-copies', ['file' => $this->file, '--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain("3 unchanged, 2 entries settled as the catalogue's own (dry run).");
        $this->assertSame(2, (clone $entries)->where('status', SourceEntry::STATUS_UNVERIFIED)->count());

        $this->artisan('catalogs:import-copies', ['file' => $this->file])->assertSuccessful()
            ->expectsOutputToContain("3 unchanged, 2 entries settled as the catalogue's own.");
        $this->assertSame(2, (clone $entries)->where('origin', SourceEntry::ORIGIN_CATALOGUE)->where('status', SourceEntry::STATUS_VERIFIED)->count());
        $this->assertSame(SourceEntry::STATUS_REJECTED, $rejected->fresh()->status);

        $this->artisan('catalogs:import-copies', ['file' => $this->file])->assertSuccessful()
            ->expectsOutputToContain('0 copies made (0 under new works), 3 unchanged.');
    }

    public function test_a_dry_run_makes_nothing()
    {
        $this->artisan('catalogs:import-copies', ['file' => $this->file, '--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain('create: 3 copies (1 under existing works, 2 under new works)');
        $this->assertNull($this->library());
    }

    public function test_a_work_the_site_lacks_stops_the_run_before_anything_is_made()
    {
        $data = $this->data();
        $data['copies'][0]['work']['slug'] = 'no-such-work';
        File::put($this->file, json_encode($data, JSON_UNESCAPED_UNICODE));

        $this->artisan('catalogs:import-copies', ['file' => $this->file])->assertFailed()->expectsOutputToContain('no-such-work');
        $this->assertNull($this->library());
    }

    public function test_undo_takes_back_the_copies_their_new_works_the_volume_and_the_library()
    {
        $this->artisan('catalogs:import-copies', ['file' => $this->file])->assertSuccessful();
        $library = $this->library();
        $newWorks = Manuscript::where('library_id', $library->id)->where('work_id', '!=', $this->riaz->id)->pluck('work_id');

        $this->artisan('catalogs:import-copies', ['file' => $this->file, '--undo' => true])->assertSuccessful()
            ->expectsOutputToContain('undo: 3 copies');

        $this->assertNull($this->library());
        $this->assertSame(0, Work::whereIn('id', $newWorks)->count());
        $this->assertTrue(Work::whereKey($this->riaz->id)->exists());
        $this->assertSame(0, $this->riaz->fresh()->manuscripts_count);
        $this->assertSame(0, CatalogVolume::where('facsimile_code', 'icc-eshkevari')->count());
        $this->assertTrue(Person::whereKey($this->attar->id)->exists());
    }

    public function test_a_format_by_name_is_labelled_as_a_format_and_not_in_centimetres()
    {
        $this->artisan('catalogs:import-copies', ['file' => $this->file])->assertSuccessful();
        $two = Manuscript::where('library_id', $this->library()->id)->where('shelfmark', '2')->firstOrFail();

        $this->followingRedirects()->get(route('manuscripts.show', $two->id))->assertOk()->assertSee('قطع')->assertSee('رحلی کوچک')->assertDontSee('اندازه (سانتی‌متر)');
    }
}
