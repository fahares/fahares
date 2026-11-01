<?php

namespace Tests\Feature;

use App\Console\Commands\SplitMohaddesArmaviCatalogueCommand as Split;
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
 * sources:split-mohaddes-armavi (step 13.127): the Mohaddes Armavi catalogue, held as one volume, gets its three and
 * each Ehya copy the volume its «محدث ارموی مخ: V» names; a backup puts it all back.
 */
class SplitMohaddesArmaviCatalogueTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-armavi-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
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

    public function test_the_catalogue_gets_its_three_volumes_and_their_copies_and_revert()
    {
        $ehya = Library::create(['name' => 'مرکز احیاء', 'city' => 'قم', 'slug' => 'ehya-' . uniqid()])->id;
        $other = Library::create(['name' => 'گلپایگانی', 'city' => 'قم', 'slug' => 'golpayegani-' . uniqid()])->id;
        $jafar = Cataloger::create(['name' => 'سید جعفر حسینی اشکوری', 'slug' => 'jafar-' . uniqid()]);
        $derayati = Cataloger::create(['name' => 'مصطفی درایتی', 'slug' => 'derayati-' . uniqid()]);

        $armavi = $this->source(Split::CODE, Split::TITLE, $ehya);
        $third = $this->volume($armavi, Split::TITLE . ' - جلد سوم', 1, ['publisher' => 'مؤسسه الجواد', 'publication_year' => 'مخطوط',
            'manuscripts_range' => '۱۰۰۱-۱۵۰۰', 'citation_pattern' => 'محدث ارموی مخ: 3']);
        $third->catalogers()->attach([$jafar->id => ['role' => 'cataloger'], $derayati->id => ['role' => 'supervisor']]);
        $printed = $this->volume($this->source('catalogue-ehya-test', 'فهرست نسخه‌های خطی مرکز احیاء میراث اسلامی', $ehya),
            'فهرست نسخه‌های خطی مرکز احیاء میراث اسلامی - جلد 1', 1);

        $first = $this->copy($ehya, '[محدث ارموی مخ: 1 - 411]', $third);
        $second = $this->copy($ehya, '[محدث ارموی مخ: 2-880]', $third);
        $stays = $this->copy($ehya, '[محدث ارموی مخ: 3-1667]', $third);
        $page = $this->copy($ehya, '[محدث ارموی مخ: 1145]', $third);
        $untied = $this->copy($ehya, '[محدث ارموی - ف مخ: 1 - 336]', null);
        $bare = $this->copy($ehya, '[محدث ارموی: 2 - 813]', null);
        $onPrinted = $this->copy($ehya, '[محدث ارموی مخ: 1 - 20]', $printed);
        $printedOwn = $this->copy($ehya, '[محدث ارموی ف: 1 - 20]', $printed);
        $elsewhere = $this->copy($other, '[محدث ارموی مخ: 1 - 5]', null);

        $before = $this->state();
        $this->artisan('sources:split-mohaddes-armavi', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, $this->state(), 'a dry run saves nothing');

        $backup = storage_path('framework/testing/armavi-' . uniqid() . '.json');
        $this->artisan('sources:split-mohaddes-armavi', ['--backup' => $backup])->assertSuccessful();

        // three volumes, the one it had the third, each with its pages, publisher and catalogers
        $volumes = $armavi->volumes()->get();
        $this->assertSame([1, 2, 3], $volumes->pluck('volume_number')->all());
        $this->assertSame($third->id, $volumes->last()->id);
        $this->assertSame([Split::TITLE . ' - جلد اول', 537, 1, 537, '۱-۵۰۰', 'مؤسسه الجواد', 'مخطوط', 'محدث ارموی مخ: 1'], [$volumes[0]->title,
            $volumes[0]->pages_count, $volumes[0]->page_first, $volumes[0]->page_last, $volumes[0]->manuscripts_range, $volumes[0]->publisher,
            $volumes[0]->publication_year, $volumes[0]->citation_pattern]);
        $this->assertSame([603, 1089, 1691], [$volumes[2]->pages_count, $volumes[2]->page_first, $volumes[2]->page_last]);
        $this->assertSame(Split::TITLE . ' - جلد دوم', CatalogVolume::find($volumes[1]->id)->title);
        foreach ($volumes as $volume) {
            $this->assertEqualsCanonicalizing([[$jafar->id, 'cataloger'], [$derayati->id, 'supervisor']],
                $volume->catalogers()->get()->map(fn ($c) => [$c->id, $c->pivot->role])->all());
        }
        $this->assertSame(3, $armavi->fresh()->volumes_count);

        // each copy on the volume its first citation names, its entry with it
        foreach ([[$first, 0], [$second, 1], [$stays, 2], [$untied, 0], [$bare, 1], [$onPrinted, 0]] as [$copy, $i]) {
            $this->assertSame($volumes[$i]->id, $copy->fresh()->catalog_volume_id, $copy->metadata['catalog_citation']);
            $this->assertSame([$armavi->id, $volumes[$i]->id, $i + 1], [$this->entry($copy)->source_id, $this->entry($copy)->source_volume_id, $this->entry($copy)->volume_number]);
        }
        $this->assertSame($third->id, $page->fresh()->catalog_volume_id, 'a page for a volume is left where it is');
        $this->assertSame($printed->id, $printedOwn->fresh()->catalog_volume_id);
        $this->assertNull($elsewhere->fresh()->catalog_volume_id, 'another library\'s copy is not touched');
        $this->assertSame([3, 2, 2, 1], [CatalogVolume::find($volumes[0]->id)->manuscripts_count, CatalogVolume::find($third->id)->manuscripts_count,
            CatalogVolume::find($volumes[1]->id)->manuscripts_count, CatalogVolume::find($printed->id)->manuscripts_count]);
        $this->assertSame(3, $jafar->fresh()->volumes_count);

        // again: nothing to do
        $after = $this->state();
        $this->artisan('sources:split-mohaddes-armavi')->assertSuccessful()->expectsOutput('Nothing to do.');
        $this->assertSame($after, $this->state());

        // the backup puts every row back and removes the volumes made
        $this->artisan('sources:split-mohaddes-armavi', ['--revert' => $backup])->assertSuccessful();
        $this->assertEquals($before, $this->state());
        @unlink($backup);
    }
}
