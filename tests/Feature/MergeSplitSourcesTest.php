<?php

namespace Tests\Feature;

use App\Console\Commands\MergeSplitSourcesCommand as Merge;
use App\Models\Catalog;
use App\Models\CatalogVolume;
use App\Models\Cataloger;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sources:merge-split (step 13.110): a catalogue the registry holds as a source per volume (Golpayegani's) or under two
 * spellings with overlapping volumes (Marashi's) becomes one source, its volumes keeping their ids and taking the
 * numbers their titles give; the folded codes redirect, and sources:backfill keeps them folded.
 */
class MergeSplitSourcesTest extends TestCase
{
    private const GOLPAYEGANI = 'فهرست نسخ خطی کتابخانه عمومی آیت‌الله گلپایگانی';

    private const MARASHI = 'فهرست نسخه‌های خطی کتابخانه عمومی آیت‌الله العظمی مرعشی نجفی';

    private const MARASHI_OTHER = 'فهرست نسخه های خطی کتابخانه عمومی حضرت آیة الله العظمی مرعشی نجفی';

    private Library $library;

    private array $volumes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانه آزمون', 'city' => 'قم', 'slug' => 'merge-test-' . uniqid()]);
        $fankha = Catalog::where('code', 'fankha')->value('id');
        $volume = fn (string $title, int $number) => $this->volumes[$title] = CatalogVolume::create(['title' => $title,
            'volume_number' => $number, 'library_id' => $this->library->id, 'catalog_id' => $fankha]);
        // a source per volume, each numbered 1
        foreach (['اول: آ - ج', 'دوم: ح - خ', 'سوم: د - ذ'] as $letters) {
            $volume(self::GOLPAYEGANI . ' - جلد ' . $letters, 1);
        }
        // two spellings, both with volume 2; the second also with 5, and the first with two parts of 24
        $volume(self::MARASHI . ' - جلد 1', 1);
        $volume(self::MARASHI . ' - جلد 2', 2);
        $volume(self::MARASHI . ' - جلد ۱/۲۴', 24);
        $volume(self::MARASHI . ' - جلد ۲/۲۴', 24);
        $volume(self::MARASHI_OTHER . ' - 2', 2);
        $volume(self::MARASHI_OTHER . ' - 5', 5);
        $this->artisan('sources:backfill', ['--only' => ['registry']])->assertSuccessful();
    }

    private function vol(string $title): SourceVolume
    {
        return SourceVolume::findOrFail($this->volumes[$title]->id);
    }

    public function test_the_titles_tell_the_volume_and_the_catalogue()
    {
        $this->assertSame(self::GOLPAYEGANI, Merge::baseTitle(self::GOLPAYEGANI . ' - جلد اول: آ - ج'));
        $this->assertSame(self::MARASHI_OTHER, Merge::baseTitle(self::MARASHI_OTHER . ' - 13'));
        $this->assertSame(Merge::normalize(self::MARASHI), Merge::normalize(self::MARASHI_OTHER));
        $this->assertSame(8, Merge::volumeNumber(self::GOLPAYEGANI . ' - جلد هشتم: ن - ی'));
        $this->assertSame(28, Merge::volumeNumber(self::MARASHI . ' - جلد بیست و هشتم'));
        $this->assertSame(30, Merge::volumeNumber(self::MARASHI . ' - جلد سی‌ام'));
        $this->assertSame(31, Merge::volumeNumber(self::MARASHI . ' - جلد سی و یکم'));
        $this->assertSame(13, Merge::volumeNumber(self::MARASHI_OTHER . ' - ۱۳'));
        $this->assertNull(Merge::volumeNumber('فهرست مختصر'));
        $this->assertTrue(Merge::isPart('فهرست - جلد سوم - بخش دوم (مجلد چهارم): تاریخ'));
        $this->assertTrue(Merge::isPart('فهرست - جلد ۳/۲۴: اهدایی طباطبائی'));
        $this->assertFalse(Merge::isPart(self::GOLPAYEGANI . ' - جلد اول: آ - ج'));
    }

    public function test_a_catalogue_held_as_several_sources_becomes_one()
    {
        $this->assertSame(3, Source::where('library_id', $this->library->id)->where('title', 'like', self::GOLPAYEGANI . '%')->count());
        $cataloger = Cataloger::create(['name' => 'سید احمد حسینی اشکوری', 'slug' => 'eshkevari-' . uniqid()]);
        $supervisor = Cataloger::create(['name' => 'سید محمود مرعشی', 'slug' => 'marashi-' . uniqid()]);
        $kept2 = $this->vol(self::MARASHI . ' - جلد 2');
        $dup2 = $this->vol(self::MARASHI_OTHER . ' - 2');
        $kept2->catalogers()->attach([$cataloger->id => ['role' => 'cataloger']]);
        $dup2->catalogers()->attach([$cataloger->id => ['role' => 'cataloger'], $supervisor->id => ['role' => 'supervisor']]);
        $dup2->update(['publication_year' => '۱۳۵۵']);
        $work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-merge-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = Manuscript::create(['work_id' => $work->id, 'library_id' => $this->library->id, 'shelfmark' => '12', 'catalog_volume_id' => $dup2->id,
            'volume_number' => 1, 'page_start' => 1]);
        $entry = fn (SourceVolume $v, ?int $number, int $page) => SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $copy->id,
            'source_id' => $v->source_id, 'source_volume_id' => $v->id, 'volume_number' => $number, 'page_start' => $page,
            'origin' => SourceEntry::ORIGIN_FANKHA_CITATION]);
        $onDuplicate = $entry($dup2, 2, 40);
        $golpayegani3 = $this->vol(self::GOLPAYEGANI . ' - جلد سوم: د - ذ');
        $onThird = $entry($golpayegani3, 1, 7);
        // a place named by its number only, in the third volume's own source
        $byNumber = SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $golpayegani3->source_id,
            'volume_number' => 1, 'page_start' => 9, 'origin' => SourceEntry::ORIGIN_MANUAL]);
        $folded = [$dup2->source->code, $golpayegani3->source->code];

        $this->artisan('sources:merge-split', ['--dry-run' => true])->assertSuccessful();
        $this->assertNotNull(SourceVolume::find($dup2->id), 'a dry run saves nothing');

        $this->artisan('sources:merge-split')->assertSuccessful();

        $golpayegani = Source::where('title', self::GOLPAYEGANI)->sole();
        $this->assertSame(3, $golpayegani->volumes_count);
        $this->assertSame([1, 2, 3], $golpayegani->volumes()->pluck('volume_number')->all());
        $this->assertSame($this->volumes[self::GOLPAYEGANI . ' - جلد اول: آ - ج']->id, $golpayegani->volumes()->first()->id, 'the volumes keep their ids');
        $this->assertSame(3, CatalogVolume::find($golpayegani3->id)->volume_number);
        $this->assertSame([$golpayegani->id, 3], [$onThird->fresh()->source_id, $onThird->fresh()->volume_number]);
        $this->assertSame([$golpayegani->id, 3], [$byNumber->fresh()->source_id, $byNumber->fresh()->volume_number]);

        $marashi = Source::whereIn('title', [self::MARASHI, self::MARASHI_OTHER])->sole();
        $this->assertSame(self::MARASHI, $marashi->title);
        $this->assertSame([1, 2, 5, 24, 24], $marashi->volumes()->pluck('volume_number')->all(), 'the parts of 24 stay two');
        $this->assertSame(5, $marashi->volumes_count);
        $this->assertNull(SourceVolume::find($dup2->id));
        $this->assertNull(CatalogVolume::find($dup2->id));
        $this->assertSame([$marashi->id, $kept2->id, 2], [$onDuplicate->fresh()->source_id, $onDuplicate->fresh()->source_volume_id, $onDuplicate->fresh()->volume_number]);
        $this->assertSame($kept2->id, $copy->fresh()->catalog_volume_id);
        $this->assertEqualsCanonicalizing([$cataloger->id, $supervisor->id], $kept2->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame('۱۳۵۵', $kept2->fresh()->publication_year);
        $this->assertSame(0, DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $dup2->id)->count());

        // the folded addresses lead to the kept source, with their query
        $this->get('/sources/' . $folded[0] . '?list=manuscripts')->assertMovedPermanently()
            ->assertRedirect(route('sources.show', $marashi->code) . '?list=manuscripts');
        $this->get('/sources/' . $folded[1])->assertMovedPermanently()->assertRedirect(route('sources.show', $golpayegani->code));
        $this->get('/sources/' . $golpayegani->code)->assertOk()->assertSee(self::GOLPAYEGANI, false);
        $this->get('/sources/nothing-here')->assertNotFound();

        // again: nothing to fold; sources:backfill leaves the folded catalogues one
        $sources = Source::count();
        $this->artisan('sources:merge-split')->assertSuccessful()->expectsOutput('No catalogue is held as several sources.');
        $this->artisan('sources:backfill', ['--only' => ['registry']])->assertSuccessful();
        $this->assertSame($sources, Source::count());
        $this->assertSame([self::GOLPAYEGANI, 3], [$golpayegani->fresh()->title, $golpayegani->fresh()->volumes_count]);
        $this->assertSame($golpayegani->id, $golpayegani3->fresh()->source_id);
        $this->assertSame(3, $golpayegani3->fresh()->volume_number);
        $this->assertSame([self::MARASHI, 5], [$marashi->fresh()->title, $marashi->fresh()->volumes_count]);
        $this->assertSame($marashi->id, SourceVolume::find($this->volumes[self::MARASHI_OTHER . ' - 5']->id)->source_id);
    }

    public function test_a_group_the_titles_do_not_tell_is_folded_by_its_codes()
    {
        $photos = Source::create(['code' => 'photos-a', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست نسخه‌های عکسی کتابخانه آزمون', 'library_id' => $this->library->id]);
        $other = Source::create(['code' => 'photos-b', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست نسخه‌های عکسی آزمون', 'library_id' => $this->library->id]);
        $a = SourceVolume::create(['source_id' => $photos->id, 'volume_number' => 2, 'title' => 'فهرست نسخه‌های عکسی کتابخانه آزمون - 2']);
        $b = SourceVolume::create(['source_id' => $other->id, 'volume_number' => 1, 'title' => 'فهرست نسخه‌های عکسی آزمون - عکسی جلد 5']);

        $this->artisan('sources:merge-split', ['--group' => ['photos-a,photos-b']])->assertSuccessful();

        $this->assertNull(Source::find($other->id));
        $this->assertSame([2, 5], $photos->volumes()->pluck('volume_number')->all());
        $this->assertSame($photos->id, $b->fresh()->source_id);
        $this->assertSame(['photos-b'], $photos->fresh()->metadata['merged_codes']);
        // the same codes again find one source and do nothing
        $this->artisan('sources:merge-split', ['--group' => ['photos-a,photos-b']])->assertSuccessful();
        $this->assertSame(2, $photos->volumes()->count());
    }
}
