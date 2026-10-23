<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** The record pages read their sources from source_entries (step 13.2), not from the old columns. */
class SourcePagesTest extends TestCase
{
    protected Work $work;

    protected Manuscript $manuscript;

    protected Source $catalogue;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('sources:backfill', ['--only' => ['registry']]);
        $library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-azam-' . uniqid()]);
        $this->work = Work::create(['primary_title' => 'دیوان قدسی', 'clean_title' => 'دیوان قدسی', 'slug' => 'work-pages-' . uniqid(),
            'volume_number' => 15, 'page_start' => 120, 'raw_text' => 'ستون کهنه‌ی اثر', 'bibliography' => ['ستون کهنه‌ی مآخذ'],
            'description' => 'معرفی آزمایشی اثر']);
        $this->manuscript = Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library->id, 'shelfmark' => '1939',
            'volume_number' => 15, 'page_start' => 120, 'sequence_number' => 3, 'raw_text' => 'ستون کهنه‌ی نسخه']);
        $this->catalogue = Source::create(['code' => 'test-catalogue', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10,
            'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', 'library_id' => $library->id]);
    }

    protected function entry(Work|Manuscript $record, array $values): SourceEntry
    {
        return SourceEntry::create($values + ['citable_type' => $record::class, 'citable_id' => $record->id, 'origin' => SourceEntry::ORIGIN_MANUAL]);
    }

    /** The Fankha entry saving the record made, changed so the page can only have its text from the entry. */
    protected function fankha(Work|Manuscript $record, array $values): SourceEntry
    {
        $entry = SourceEntry::where('citable_type', $record::class)->where('citable_id', $record->id)->where('is_primary', true)->sole();
        $entry->update($values + ['page_start' => 121, 'text_page' => 121]);

        return $entry;
    }

    public function test_the_manuscript_page_shows_the_library_catalogue_before_fankha()
    {
        $this->fankha($this->manuscript, ['copy_text' => "متن فنخای نسخه <!-- page: 121 -->\nادامه"]);
        $volume = SourceVolume::create(['source_id' => $this->catalogue->id, 'volume_number' => 2, 'facsimile_code' => 'masjid-azam-ostadi', 'edition_label' => '۱۳۸۸، به کوشش رضا استادی']);
        $this->entry($this->manuscript, ['source_id' => $this->catalogue->id, 'source_volume_id' => $volume->id, 'volume_number' => 2, 'page_start' => 414,
            'entry_text' => 'متن مدخل فهرست', 'copy_text' => 'ش 1939، 162 برگ', 'status' => SourceEntry::STATUS_VERIFIED]);
        $this->entry($this->manuscript, ['source_id' => 1, 'volume_number' => 34, 'page_start' => 970, 'role' => SourceEntry::ROLE_ADDENDUM,
            'origin' => SourceEntry::ORIGIN_FANKHA, 'copy_text' => 'متن استدراک']);

        $this->get(route('manuscripts.show', $this->manuscript))->assertOk()
            // every source alike, in the order of its rank: no «متن مأخذ» above the others
            ->assertSeeInOrder(['در مآخذ نسخه‌شناسی', 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم، چاپ ۱۳۸۸، به کوشش رضا استادی، ج ۲، ص ۴۱۴', 'فنخا، ج ۱۵، ص ۱۲۱، ردیف ۳', 'فنخا (استدراک)، ج ۳۴، ص ۹۷۰'])
            ->assertSeeInOrder(['متن مأخذ', 'فهرست کتابخانه مسجد اعظم، چاپ ۱۳۸۸', 'متن مدخل فهرست', 'ش ۱۹۳۹، ۱۶۲ برگ', 'فهرستگان نسخه‌های خطی ایران (فنخا)', 'ج ۱۵، ص ۱۲۱، ردیف ۳', "متن فنخای نسخه \nادامه", 'فهرستگان نسخه‌های خطی ایران (فنخا)، استدراک', 'متن استدراک'])
            ->assertDontSee('ارجاع‌های دیگر')
            ->assertSee('catalogs/fankha/v15/p0121.webp', false)
            ->assertDontSee('ستون کهنه‌ی نسخه')
            ->assertDontSee('<!-- page', false);
        $this->assertStringNotContainsString('data-stale-text', $this->get(route('manuscripts.show', $this->manuscript))->getContent());

        // an address corrected after the text was taken marks the text
        SourceEntry::where('citable_id', $this->manuscript->id)->where('source_id', $this->catalogue->id)
            ->update(['text_volume_number' => 2, 'text_page' => 414, 'page_start' => 416]);
        $this->get(route('manuscripts.show', $this->manuscript))
            ->assertSee('این متن از نشانی پیشین (ج ۲، ص ۴۱۴) برداشته شده', false)->assertSee('متن مدخل فهرست');
    }

    public function test_a_rejected_entry_leaves_the_page_and_the_cited_volume_takes_its_place()
    {
        $this->fankha($this->manuscript, ['copy_text' => 'متن فنخای نسخه']);
        $cited = SourceVolume::create(['source_id' => $this->catalogue->id, 'volume_number' => 2, 'title' => 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد دوم']);
        $this->entry($this->manuscript, ['source_id' => $this->catalogue->id, 'source_volume_id' => $cited->id, 'origin' => SourceEntry::ORIGIN_FANKHA_CITATION, 'citation_text' => '[ف: 2-414]']);
        $volume = SourceVolume::create(['source_id' => $this->catalogue->id, 'volume_number' => 2, 'facsimile_code' => 'masjid-azam-ostadi']);
        $this->entry($this->manuscript, ['source_id' => $this->catalogue->id, 'source_volume_id' => $volume->id, 'volume_number' => 2, 'page_start' => 414,
            'entry_text' => 'متن مدخل فهرست', 'origin' => SourceEntry::ORIGIN_MATCH, 'status' => SourceEntry::STATUS_REJECTED]);

        $this->get(route('manuscripts.show', $this->manuscript))->assertOk()
            ->assertDontSee('متن مدخل فهرست')->assertDontSee('masjid-azam-ostadi/v02/p0414.webp', false)
            ->assertSee('فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد دوم');
    }

    public function test_the_work_page_shows_its_entry_and_bibliography_from_the_entries()
    {
        $this->fankha($this->work, ['entry_text' => 'متن فنخای اثر', 'page_end' => 122, 'role' => SourceEntry::ROLE_ADDENDUM]);
        $dharia = Source::where('code', 'dharia')->value('id');
        $this->entry($this->work, ['source_id' => null, 'role' => SourceEntry::ROLE_CITATION, 'citation_text' => 'دنا 2/207', 'sort_order' => 1]);
        $this->entry($this->work, ['source_id' => $dharia, 'role' => SourceEntry::ROLE_CITATION, 'citation_text' => 'الذریعة 101/25', 'sort_order' => 0]);

        $this->get(route('works.show', $this->work))->assertOk()
            ->assertSeeInOrder(['مآخذ کتاب‌شناسی', 'الذریعة', 'دنا', 'فهرستگان نسخه‌های خطی ایران', '(استدراک)، ', 'ج ۱۵، ص ۱۲۱–۱۲۲', 'متن مأخذ', 'فهرستگان نسخه‌های خطی ایران (فنخا)، استدراک', 'متن فنخای اثر'])
            ->assertSee("field: 'bibliography_page_dharia_25_101'", false)
            ->assertSeeInOrder(['معرفی اثر', 'به روایت فنخا', 'معرفی آزمایشی اثر'])
            ->assertSee('استدراک')
            // the suggestion desk still offers the bibliography column, which corrections write until step 13.3
            ->assertDontSee('ستون کهنه‌ی اثر');
    }
}
