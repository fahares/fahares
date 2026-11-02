<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\CatalogVolumePart;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Parts of a catalogue volume (step 13.150): a copy of a volume of several libraries' catalogues is of its library's
 * part, takes its first cataloger, and reaches only its catalogers; the volume's people are its editors.
 */
class CatalogVolumePartsTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-parts-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function library(string $name): Library
    {
        return Library::create(['name' => $name, 'city' => 'مشهد', 'slug' => 'lib-' . uniqid()]);
    }

    private function volume(Source $source, int $number): SourceVolume
    {
        $columns = ['title' => $source->title . ' - دفتر ' . $number, 'volume_number' => $number];
        $cv = CatalogVolume::create($columns);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], $columns + ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function part(SourceVolume $volume, ?Library $library, string $title, ?int $first, ?int $last, array $catalogers): CatalogVolumePart
    {
        $part = CatalogVolumePart::create(['catalog_volume_id' => $volume->id, 'library_id' => $library?->id, 'title' => $title,
            'page_first' => $first, 'page_last' => $last, 'sort' => $first ?? 0]);
        foreach ($catalogers as $cataloger) {
            $part->catalogers()->attach($cataloger->id, ['role' => 'cataloger']);
        }

        return $part;
    }

    private function copy(Library $library, string $citation, ?SourceVolume $volume, ?Cataloger $cataloger = null): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library->id, 'library' => $library->name, 'shelfmark' => (string) random_int(1, 99999),
            'catalog_volume_id' => $volume?->id, 'cataloger_id' => $cataloger?->id, 'volume_number' => 1, 'page_start' => 1, 'metadata' => ['catalog_citation' => $citation]]);
    }

    public function test_the_part_of_a_copy_by_its_library_and_page()
    {
        $parts = collect([
            new CatalogVolumePart(['id' => 1, 'library_id' => 10, 'page_first' => 8, 'page_last' => 107]),
            new CatalogVolumePart(['id' => 2, 'library_id' => 20, 'page_first' => 132, 'page_last' => 140]),
            new CatalogVolumePart(['id' => 3, 'library_id' => 20, 'page_first' => 141, 'page_last' => 152]),
            new CatalogVolumePart(['id' => 4, 'library_id' => 30, 'page_first' => null, 'page_last' => null]),
        ])->each(fn ($p, $i) => $p->id = $i + 1);
        $found = fn ($library, $page) => [CatalogVolumePart::choose($parts, $library, $page)[0]?->id, CatalogVolumePart::choose($parts, $library, $page)[1]];

        $this->assertSame([1, 'library'], $found(10, 500), 'its library\'s one part, whatever the page');
        $this->assertSame([3, 'page'], $found(20, 145));
        $this->assertSame([null, 'none'], $found(20, 200), 'of two parts of its library, neither holds the page');
        $this->assertSame([4, 'library'], $found(30, null));
        $this->assertSame([2, 'by_page'], $found(99, 133), 'another library, but one part holds the page');
        $this->assertSame([null, 'none'], $found(99, 600));
        $this->assertSame([null, 'none'], $found(null, null));
    }

    public function test_copies_reach_the_catalogers_of_their_part_and_the_volume_people_edit()
    {
        $afshar = Cataloger::create(['name' => 'ایرج افشار', 'slug' => 'afshar-' . uniqid()]);
        $daneshPajouh = Cataloger::create(['name' => 'محمدتقی دانش‌پژوه', 'slug' => 'dp-' . uniqid()]);
        $valaei = Cataloger::create(['name' => 'مهدی والئی', 'slug' => 'valaei-' . uniqid()]);
        $rowzati = Cataloger::create(['name' => 'سید محمدعلی روضاتی', 'slug' => 'rowzati-' . uniqid()]);
        $nashriyeh = Source::create(['code' => 'nashriyeh-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران']);
        $fifth = $this->volume($nashriyeh, 5);
        $plain = $this->volume($nashriyeh, 4);
        $fifth->catalogers()->attach([$daneshPajouh->id => ['role' => 'cataloger'], $afshar->id => ['role' => 'cataloger']]);
        $plain->catalogers()->attach([$daneshPajouh->id => ['role' => 'cataloger']]);

        $molavi = $this->library('مولوی');
        $isfahan = $this->library('روضاتی، سید محمد علی');
        $other = $this->library('آستان قدس');
        $molaviPart = $this->part($fifth, $molavi, 'کتابخانه عبدالمجید مولوی', 8, 107, [$valaei]);
        $rowzatiPart = $this->part($fifth, $isfahan, 'کتابخانه سید محمد علی روضاتی', 132, 152, [$rowzati, $daneshPajouh]);

        $byLibrary = $this->copy($molavi, '[نشریه: 5 - 40]', $fifth, $daneshPajouh);
        $outside = $this->copy($molavi, '[نشریه: 5 - 300]', $fifth, $daneshPajouh);
        $rowzatiCopy = $this->copy($isfahan, '[نشریه: 5 - 133]', $fifth, $daneshPajouh);
        $byPage = $this->copy($other, '[نشریه: 5 - 140]', $fifth, $daneshPajouh);
        $none = $this->copy($other, '[نشریه: 5 - 600]', $fifth, $daneshPajouh);
        $onPlain = $this->copy($other, '[نشریه: 4 - 20]', $plain, $daneshPajouh);

        // made on a volume with parts: SourceEntrySync::syncCited gives each its part and the part's first cataloger
        $this->assertSame([$molaviPart->id, $valaei->id], [$byLibrary->fresh()->catalog_volume_part_id, $byLibrary->fresh()->cataloger_id]);
        $this->assertSame($molaviPart->id, $outside->fresh()->catalog_volume_part_id, 'its library\'s one part, though the page is outside it');
        $this->assertSame([$rowzatiPart->id, $rowzati->id], [$rowzatiCopy->fresh()->catalog_volume_part_id, $rowzatiCopy->fresh()->cataloger_id]);
        $this->assertSame($rowzatiPart->id, $byPage->fresh()->catalog_volume_part_id);
        $this->assertSame([null, $daneshPajouh->id], [$none->fresh()->catalog_volume_part_id, $none->fresh()->cataloger_id]);
        $this->assertNull($onPlain->fresh()->catalog_volume_part_id);

        $this->artisan('catalogs:assign-volume-parts')->assertSuccessful()->expectsOutput('5 copies read.')
            ->expectsOutputToContain('page outside their part: 1');
        $this->assertSame([2, 2], [$molaviPart->fresh()->manuscripts_count, $rowzatiPart->fresh()->manuscripts_count]);

        // the counts: a part's catalogers its copies, the volume's people the rest as editors
        $counts = fn (Cataloger $c) => [$c->fresh()->volumes_count, $c->fresh()->manuscripts_count, $c->fresh()->supervised_count];
        $this->assertSame([1, 2, 0], $counts($valaei));
        $this->assertSame([1, 2, 0], $counts($rowzati));
        $this->assertSame([2, 4, 5], $counts($daneshPajouh), 'the plain volume\'s copy, the Rowzati part\'s two, and «none» that names him; editor of the five on the fifth');
        $this->assertSame([1, 0, 5], $counts($afshar));
        $this->assertSame(4, $daneshPajouh->catalogedCopies()->count());

        // a copy that leaves the volume leaves its part and gets its new volume's cataloger back
        $byLibrary->update(['catalog_volume_id' => $plain->id]);
        $this->assertSame([null, $daneshPajouh->id], [$byLibrary->fresh()->catalog_volume_part_id, $byLibrary->fresh()->cataloger_id]);
        $this->assertSame(1, $molaviPart->fresh()->manuscripts_count);

        // the pages: the volume's parts, the copy's part and editors, the cataloger's parts and edited copies
        $this->get(route('sources.show', $nashriyeh->code))->assertOk()->assertSee('data-volume-part', false)
            ->assertSee('کتابخانه عبدالمجید مولوی')->assertSee('زیر نظر');
        $this->get(route('manuscripts.show', $rowzatiCopy))->assertOk()->assertSee('data-cataloger-part', false)
            ->assertSee('بخش «کتابخانه سید محمد علی روضاتی»')->assertSee('با محمدتقی دانش‌پژوه');
        $this->get(route('catalogers.show', $valaei))->assertOk()->assertSee('data-cataloger-part', false)->assertSee('کتابخانه عبدالمجید مولوی');
        $this->get(route('catalogers.show', $afshar))->assertOk()->assertSee('data-cataloger-supervised', false);
    }

    public function test_a_dry_run_saves_nothing()
    {
        $source = Source::create(['code' => 'parts-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'نسخه‌پژوهی']);
        $volume = $this->volume($source, 1);
        $library = $this->library('مدرسه منصوریه');
        $copy = $this->copy($library, '[نسخه پژوهی: 1 - 50]', $volume);
        $this->part($volume, $library, 'کتابخانه مدرسه منصوریه', 49, 55, [Cataloger::create(['name' => 'محمد برکت', 'slug' => 'barakat-' . uniqid()])]);

        $this->artisan('catalogs:assign-volume-parts', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('changed: 1');
        $this->assertNull($copy->fresh()->catalog_volume_part_id);
        $this->artisan('catalogs:assign-volume-parts')->assertSuccessful();
        $this->assertNotNull($copy->fresh()->catalog_volume_part_id);
    }

    public function test_a_library_merge_takes_its_parts_along_and_its_undo_brings_them_back()
    {
        $source = Source::create(['code' => 'parts-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'نسخه‌پژوهی']);
        $volume = $this->volume($source, 1);
        $kept = $this->library('علامه طباطبائی');
        $gone = $this->library('طباطبائی');
        $part = $this->part($volume, $gone, 'کتابخانه علامه طباطبائی', 18, 42, []);

        $merger = app(\App\Suggestions\LibraryMerger::class);
        $old = $merger->merge($gone, $kept);
        $this->assertSame($kept->id, $part->fresh()->library_id);
        $merger->undoMerge($kept->fresh(), $old);
        $this->assertSame($gone->id, $part->fresh()->library_id);
    }
}
