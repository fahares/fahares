<?php

namespace Tests\Feature;

use App\Console\Commands\SplitEhyaPhotographicCatalogueCommand as Split;
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
 * sources:split-ehya-photographic (step 13.122): Markaz-e Ehya's photographic catalogue, held as one volume, gets its
 * seven and each copy the volume its «عکسی ف: V» names; Masjid-e A'zam's copies cited «ف مخ: V» leave the catalogues
 * they were put on for their own; a backup puts it all back.
 */
class SplitEhyaPhotographicCatalogueTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-photo-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function source(string $code, string $title, int $library): Source
    {
        return Source::create(['code' => $code, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => $title, 'library_id' => $library]);
    }

    /** A volume of a source, with its catalog volume under the same id. */
    private function volume(Source $source, string $title, int $number, array $columns = []): SourceVolume
    {
        $columns = ['title' => $title, 'volume_number' => $number] + $columns;
        $cv = CatalogVolume::create($columns + ['library_id' => $source->library_id]);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], $columns + ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function copy(int $library, string $citation, ?SourceVolume $volume): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library, 'shelfmark' => (string) random_int(1, 99999),
            'catalog_volume_id' => $volume?->id, 'volume_number' => 1, 'page_start' => 1, 'metadata' => ['catalog_citation' => $citation]]);
    }

    private function entry(Manuscript $copy): SourceEntry
    {
        return SourceEntry::where('legacy_type', 'fankha_citation')->where('legacy_id', $copy->id)->firstOrFail();
    }

    private function state(): array
    {
        $rows = fn ($table, $columns = ['*']) => DB::table($table)->orderBy('id')->get($columns)->map(fn ($r) => (array) $r)->all();

        return [
            'sources' => $rows('sources'),
            'source_volumes' => $rows('source_volumes'),
            'catalog_volumes' => $rows('catalog_volumes'),
            'pivot' => DB::table('catalog_volume_cataloger')->orderBy('catalog_volume_id')->orderBy('cataloger_id')
                ->get(['catalog_volume_id', 'cataloger_id', 'role'])->map(fn ($r) => (array) $r)->all(),
            'entries' => $rows('source_entries', ['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'copies' => $rows('manuscripts', ['id', 'catalog_volume_id']),
            'catalogers' => $rows('catalogers', ['id', 'volumes_count', 'manuscripts_count']),
        ];
    }

    public function test_the_photographic_catalogue_gets_its_volumes_and_masjid_its_copies_and_revert()
    {
        $ehya = Library::create(['name' => 'مرکز احیاء', 'city' => 'قم', 'slug' => 'ehya-' . uniqid()])->id;
        $masjidLibrary = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-' . uniqid()])->id;
        $golpayegani = Library::create(['name' => 'گلپایگانی', 'city' => 'قم', 'slug' => 'golpayegani-' . uniqid()])->id;
        [$ahmad, $jafar, $sadeq] = array_map(fn ($mtif) => Cataloger::create(['name' => 'اشکوری ' . $mtif, 'slug' => 'eshkevari-' . $mtif . uniqid(), 'mtif_entry_id' => $mtif]),
            [37228, 37267, 37268]);

        $photo = $this->source(Split::PHOTOGRAPHIC, Split::TITLE, $ehya);
        $seventh = $this->volume($photo, Split::TITLE . ' - عکسی جلد 7', 1, ['manuscripts_range' => '2401-2800', 'citation_pattern' => 'عکسی ف: 7']);
        $seventh->catalogers()->attach([$ahmad->id => ['role' => 'supervisor'], $sadeq->id => ['role' => 'cataloger']]);
        $manuscripts = $this->source(Split::MANUSCRIPTS, 'فهرست نسخه های خطی مرکز احیاء میراث اسلامی', $ehya);
        // the record of the photographic first volume still on the manuscript catalogue's
        $first = $this->volume($manuscripts, 'فهرست نسخه‌های خطی مرکز احیاء میراث اسلامی - جلد 1', 1, ['mtif_entry_id' => 21319]);
        $second = $this->volume($manuscripts, 'فهرست نسخه‌های خطی مرکز احیاء میراث اسلامی - جلد 2', 2);
        $masjid = $this->source(Split::MASJID, 'فهرست نسخ خطی کتابخانه مسجد اعظم قم', $masjidLibrary);
        $masjid1 = $this->volume($masjid, 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد اول', 1);
        $masjid2 = $this->volume($masjid, 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد دوم', 2);
        $golpayeganiFourth = $this->volume($this->source('catalogue-golpayegani-test', 'فهرست نسخ خطی کتابخانه عمومی آیت‌الله گلپایگانی', $golpayegani),
            'فهرست نسخ خطی کتابخانه عمومی آیت‌الله گلپایگانی - جلد چهارم: ر - س', 4);

        $onSeventh2 = $this->copy($ehya, '[عکسی ف: 2-106]', $seventh);
        $onSeventh7 = $this->copy($ehya, '[عکسی ف: 7-99]', $seventh);
        $photography = $this->copy($ehya, '[عکاسی ف: 1-194]', $first);
        $both = $this->copy($ehya, '[ف: 2 - 444 و عکسی ف: 5 - 350]', $seventh);
        $reversed = $this->copy($ehya, '[عکسی ف: 412-2]', $seventh);
        $own = $this->copy($ehya, '[ف: 1 - 5]', $first);
        $untied = $this->copy($ehya, '[عکسی ف: 6 - 3]', null);
        $masjidOnPhoto = $this->copy($masjidLibrary, '[ف مخ: 2 - 654]', $seventh);
        $masjidOnGolpayegani = $this->copy($masjidLibrary, '[ف مخ: 1 - 5]', $golpayeganiFourth);
        $masjidHome = $this->copy($masjidLibrary, '[ف مخ: 2 - 700]', $masjid2);
        $golpayeganiOwn = $this->copy($golpayegani, '[ف: 4-2180]', $golpayeganiFourth);

        $before = $this->state();
        $this->artisan('sources:split-ehya-photographic', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, $this->state(), 'a dry run saves nothing');

        $backup = storage_path('framework/testing/photo-' . uniqid() . '.json');
        $this->artisan('sources:split-ehya-photographic', ['--backup' => $backup])->assertSuccessful();

        // seven volumes, the one it had the seventh, each with its record, pages, year and catalogers
        $volumes = $photo->volumes()->get();
        $this->assertSame(range(1, 7), $volumes->pluck('volume_number')->all());
        $this->assertSame($seventh->id, $volumes->last()->id);
        $this->assertSame([21319, 21320, 21321, 21322, 21428, 66618, 66617], $volumes->pluck('mtif_entry_id')->all());
        $this->assertSame([Split::TITLE . ' - جلد 7', 420, '۱۳۸۷', 'عکسی ف: 7'], [$volumes->last()->title, $volumes->last()->pages_count,
            $volumes->last()->publication_year, $volumes->last()->citation_pattern]);
        $this->assertSame(Split::TITLE . ' - جلد 2', CatalogVolume::find($volumes[1]->id)->title);
        $this->assertNull($first->fresh()->mtif_entry_id, 'the record leaves the manuscript catalogue');
        $this->assertEqualsCanonicalizing([[$ahmad->id, 'supervisor'], [$jafar->id, 'cataloger'], [$sadeq->id, 'cataloger']],
            $volumes->first()->catalogers()->get()->map(fn ($c) => [$c->id, $c->pivot->role])->all());
        $this->assertSame([[$jafar->id, 'cataloger']], $volumes->last()->catalogers()->get()->map(fn ($c) => [$c->id, $c->pivot->role])->all());
        $this->assertSame(7, $photo->fresh()->volumes_count);

        // each copy on the volume its first citation names, its entry with it
        $volume = fn (int $n) => $volumes->firstWhere('volume_number', $n)->id;
        foreach ([[$onSeventh2, $photo, $volume(2), 2], [$onSeventh7, $photo, $seventh->id, 7], [$photography, $photo, $volume(1), 1],
            [$both, $manuscripts, $second->id, 2], [$untied, $photo, $volume(6), 6], [$own, $manuscripts, $first->id, 1],
            [$masjidOnPhoto, $masjid, $masjid2->id, 2], [$masjidOnGolpayegani, $masjid, $masjid1->id, 1], [$masjidHome, $masjid, $masjid2->id, 2]] as [$copy, $source, $id, $n]) {
            $this->assertSame($id, $copy->fresh()->catalog_volume_id, $copy->metadata['catalog_citation']);
            $this->assertSame([$source->id, $id, $n], [$this->entry($copy)->source_id, $this->entry($copy)->source_volume_id, $this->entry($copy)->volume_number]);
        }
        $this->assertSame($seventh->id, $reversed->fresh()->catalog_volume_id, 'a volume past the seventh is left where it is');
        $this->assertSame($golpayeganiFourth->id, $golpayeganiOwn->fresh()->catalog_volume_id);
        $this->assertSame([2, 1, 1], [CatalogVolume::find($seventh->id)->manuscripts_count, CatalogVolume::find($golpayeganiFourth->id)->manuscripts_count,
            CatalogVolume::find($masjid1->id)->manuscripts_count]);
        $this->assertSame([7, 6], [$jafar->fresh()->volumes_count, $ahmad->fresh()->volumes_count]);

        // again: nothing to do
        $after = $this->state();
        $this->artisan('sources:split-ehya-photographic')->assertSuccessful()->expectsOutput('Nothing to do.');
        $this->assertSame($after, $this->state());

        // the backup puts every row back and removes the volumes made
        $this->artisan('sources:split-ehya-photographic', ['--revert' => $backup])->assertSuccessful();
        $this->assertEquals($before, $this->state());
        @unlink($backup);
    }
}
