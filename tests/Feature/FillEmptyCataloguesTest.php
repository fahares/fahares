<?php

namespace Tests\Feature;

use App\Console\Commands\FillEmptyCataloguesCommand as Fill;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sources:fill-empty-catalogues (step 13.112): Tehran's microfilm catalogue, held as three sources with its third volume
 * numbered 1, becomes one and each copy goes to the volume its citation names; Majlis' brief catalogue takes the volume
 * of the main catalogue that held its copies.
 */
class FillEmptyCataloguesTest extends TestCase
{
    private const FILMS = 'فهرست میکروفیلم‌های کتابخانه مرکزی دانشگاه تهران';

    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-fill-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function source(string $code, string $title, ?int $library): Source
    {
        return Source::create(['code' => $code, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => $title, 'library_id' => $library]);
    }

    /** A volume of a source, with its catalog volume under the same id. */
    private function volume(Source $source, string $title, ?int $number, ?int $mtif = null): SourceVolume
    {
        $cv = CatalogVolume::create(['title' => $title, 'volume_number' => $number, 'library_id' => $source->library_id, 'mtif_entry_id' => $mtif]);
        // saving a catalog volume may already have given it a source volume of its own
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], ['source_id' => $source->id, 'title' => $title, 'volume_number' => $number,
            'mtif_entry_id' => $mtif, 'created_at' => now(), 'updated_at' => now()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function copy(int $library, string $citation, ?SourceVolume $volume): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library, 'shelfmark' => uniqid() . '-ف',
            'catalog_volume_id' => $volume?->id, 'volume_number' => 1, 'page_start' => 1, 'metadata' => ['catalog_citation' => $citation]]);
    }

    private function entry(Manuscript $copy): ?SourceEntry
    {
        return SourceEntry::where('legacy_type', 'fankha_citation')->where('legacy_id', $copy->id)->first();
    }

    public function test_the_microfilm_and_brief_catalogues_get_their_copies()
    {
        $tehran = Library::create(['name' => 'دانشگاه', 'city' => 'تهران', 'slug' => Fill::MICROFILMS_LIBRARY])->id;
        $majlis = Library::create(['name' => 'مجلس', 'city' => 'تهران', 'slug' => 'majlis-fill-' . uniqid()])->id;

        $films = $this->source(Fill::MICROFILMS, self::FILMS, $tehran);
        $third = $this->volume($films, self::FILMS . ' - جلد سوم', 1, Fill::MICROFILMS_FIRST_MTIF);
        $split = $this->source('catalogue-d788e304d962', 'فهرست میکرو فیلمهای کتابخانه مرکزی دانشگاه تهران', $tehran);
        $second = $this->volume($split, 'فهرست میکرو فیلمهای کتابخانه مرکزی دانشگاه تهران - 2', 2, 21484);
        $thirdAgain = $this->volume($split, 'فهرست میکرو فیلمهای کتابخانه مرکزی دانشگاه تهران - 3', 3, 21483);
        $unlibraried = $this->source(Fill::MICROFILMS_FIRST, self::FILMS, null);
        $first = $this->volume($unlibraried, self::FILMS, 1);
        $meshkat = $this->volume($this->source('catalogue-meshkat-test', 'فهرست کتابخانه دانشگاه تهران (اهدایی مشکوه)', $tehran),
            'فهرست کتابخانه دانشگاه تهران (اهدایی مشکوه) - جلد اول', 1);

        $onThird = $this->copy($tehran, '[فیلمها ف: 1 - 10]', $third);
        $onMeshkat = $this->copy($tehran, '[فیلم‌ها - ف: 2-5]', $meshkat);
        $third3 = $this->copy($tehran, '[فیلمها ف: 3 - 7]', $third);
        $untied = $this->copy($tehran, '[فيلمها ف: 3-9]', null);
        $pageOnly = $this->copy($tehran, '[فیلمها ف: - 126]', $third);
        $own = $this->copy($tehran, '[ف: 1 - 15]', $meshkat);

        $main = $this->source('catalogue-af2952129bfd', 'فهرست کتابخانه مجلس شورای اسلامی', $majlis);
        $this->volume($main, 'فهرست کتابخانه مجلس شورای اسلامی - جلد دوم', 2);
        $held = $this->volume($main, Fill::BRIEF_HELD_AS, null);
        $brief = $this->source(Fill::BRIEF, 'فهرست مختصر نسخه های خطی کتابخانه مجلس شورای اسلامی', $majlis);
        $record = $this->volume($brief, 'فهرست مختصر نسخه های خطی کتابخانه مجلس شورای اسلامی - 1', 1, Fill::BRIEF_MTIF);
        $behbahani = Cataloger::create(['name' => 'سید محمد طباطبائی بهبهانی', 'slug' => 'behbahani-' . uniqid()]);
        $record->catalogers()->attach([$behbahani->id => ['role' => 'cataloger']]);
        $short = $this->copy($majlis, '[مختصر - ف: - 112]', $held);
        $this->assertSame($main->id, $this->entry($short)->source_id);

        $this->artisan('sources:fill-empty-catalogues', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($third->id, $onThird->fresh()->catalog_volume_id, 'a dry run saves nothing');
        $this->assertNotNull(Source::find($split->id));

        $this->artisan('sources:fill-empty-catalogues')->assertSuccessful();

        // one microfilm catalogue, its volumes numbered as Fankha cites them, each with its MTIF record
        $this->assertNull(Source::find($split->id));
        $this->assertNull(Source::find($unlibraried->id));
        $this->assertSame([1, 2, 3], $films->volumes()->pluck('volume_number')->all());
        $this->assertSame([$first->id, $second->id, $third->id], $films->volumes()->pluck('id')->all());
        $this->assertNull(SourceVolume::find($thirdAgain->id));
        $this->assertSame([Fill::MICROFILMS_FIRST_MTIF, 21483], [$first->fresh()->mtif_entry_id, $third->fresh()->mtif_entry_id]);
        $this->assertSame(Fill::MICROFILMS_FIRST_MTIF, CatalogVolume::find($first->id)->mtif_entry_id);

        // each copy on the volume it cites, its entry with it
        foreach ([[$onThird, $first, 1], [$onMeshkat, $second, 2], [$third3, $third, 3], [$untied, $third, 3]] as [$copy, $volume, $number]) {
            $this->assertSame($volume->id, $copy->fresh()->catalog_volume_id);
            $this->assertSame([$films->id, $volume->id, $number], [$this->entry($copy)->source_id, $this->entry($copy)->source_volume_id, $this->entry($copy)->volume_number]);
        }
        $this->assertSame($third->id, $pageOnly->fresh()->catalog_volume_id, 'a citation with no volume stays where it is');
        $this->assertSame($meshkat->id, $own->fresh()->catalog_volume_id);

        // the brief catalogue holds its copies, under its own title, record and cataloger
        $held = $held->fresh();
        $this->assertSame([$brief->id, 1, Fill::BRIEF_MTIF], [$held->source_id, $held->volume_number, $held->mtif_entry_id]);
        $this->assertSame($record->title, $held->title);
        $this->assertSame($record->title, CatalogVolume::find($held->id)->title);
        $this->assertNull(SourceVolume::find($record->id));
        $this->assertSame([$behbahani->id], $held->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame([$brief->id, $held->id], [$this->entry($short)->source_id, $this->entry($short)->source_volume_id]);
        $this->assertSame($held->id, $short->fresh()->catalog_volume_id);
        $this->assertSame([1, 1], [$brief->fresh()->volumes_count, $main->fresh()->volumes_count]);
        $this->get('/sources/' . Fill::BRIEF . '?list=manuscripts')->assertOk()->assertSee('الکافی', false);
        $this->get('/sources/catalogue-d788e304d962')->assertMovedPermanently()->assertRedirect(route('sources.show', Fill::MICROFILMS));

        // again: nothing moves
        $state = fn () => [Source::count(), SourceVolume::count(), Manuscript::orderBy('id')->pluck('catalog_volume_id')->all(),
            SourceEntry::orderBy('id')->get(['source_id', 'source_volume_id', 'volume_number'])->toArray()];
        $before = $state();
        $this->artisan('sources:fill-empty-catalogues')->assertSuccessful()
            ->expectsOutput('Microfilm volumes: their MTIF records are in place.')
            ->expectsOutput('Brief Majlis catalogue: its copies are on its own source.');
        $this->assertSame($before, $state());
    }
}
