<?php

namespace Tests\Feature;

use App\Console\Commands\MoveCitedPartCommand;
use App\Models\AuditLog;
use App\Models\Catalog;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Sources\SourceEntrySync;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** catalogs:move-cited-part (step 14.152): copies Fankha cites in part 1 of a volume move to that part's volume. */
class MoveCitedPartTest extends TestCase
{
    public function test_the_part_a_citation_names_is_read_either_way_round()
    {
        $this->assertSame(1, MoveCitedPartCommand::partOf('[ف: 33/1 - 784]', 33));
        $this->assertSame(1, MoveCitedPartCommand::partOf('[ف: 1 / 33 - 43]', 33));
        $this->assertSame(2, MoveCitedPartCommand::partOf('[ف: 2/33-150]', 33));
        $this->assertSame(2, MoveCitedPartCommand::partOf('[ف: 33/ 2– 55]', 33));
        $this->assertNull(MoveCitedPartCommand::partOf('[ف: 33-24]', 33));
        $this->assertNull(MoveCitedPartCommand::partOf('[ف: 24/3 - 10]', 33));
    }

    public function test_copies_citing_part_one_move_with_their_reference_and_cited_page()
    {
        $library = Library::create(['name' => 'مجلس', 'city' => 'تهران', 'slug' => 'majlis-mcp-' . uniqid()]);
        $fankha = Catalog::firstOrCreate(['code' => 'fankha'], ['name' => 'فهرستگان نسخه‌های خطی ایران', 'short_name' => 'فنخا']);
        $volume = fn (string $title) => CatalogVolume::create(['title' => "فهرست مجلس آزمایشی - {$title}", 'volume_number' => 33,
            'library_id' => $library->id, 'catalog_id' => $fankha->id]);
        $part2 = $volume('جلد ۲/۳۳');
        $part1 = $volume('جلد ۱/۳۳');
        $work = Work::create(['primary_title' => 'رساله', 'clean_title' => 'رساله', 'slug' => 'work-mcp-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = function (string $shelf, string $citation) use ($library, $work, $part2) {
            $m = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => $shelf, 'catalog_volume_id' => $part2->id,
                'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1, 'metadata' => ['catalog_citation' => $citation]]);

            return $m;
        };
        $one = $copy('10470', '[ف: 1 / 33 - 43]');
        $two = $copy('11250', '[ف: 33/2-150]');
        $none = $copy('1-طباطبائی', '[ف: 33-24]');
        SourceEntrySync::syncCited(\App\Support\EntryColumns::query('manuscripts', ['id', 'catalog_citation', 'raw_text', 'catalog_volume_id'])->whereIn('manuscripts.id', [$one->id, $two->id, $none->id])->get());
        $args = ['--library' => $library->slug, '--volume' => 33, '--part' => 1, '--from' => $part2->title, '--to' => $part1->title];

        $this->artisan('catalogs:move-cited-part', $args)->expectsOutputToContain('1 copies to move')->assertSuccessful();
        $this->assertSame($part2->id, $one->fresh()->citedEntry?->source_volume_id);

        $this->artisan('catalogs:move-cited-part', $args + ['--apply' => true])->expectsOutputToContain('Moved 1 copies.')->assertSuccessful();
        $this->assertSame($part1->id, $one->fresh()->citedEntry?->source_volume_id);
        $this->assertSame($part2->id, $two->fresh()->citedEntry?->source_volume_id);
        $this->assertSame($part2->id, $none->fresh()->citedEntry?->source_volume_id);
        $entry = SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $one->id)->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)->sole();
        $this->assertSame($part1->id, $entry->source_volume_id);
        $this->assertSame(43, $entry->page_start);
        $this->assertSame($part2->id, SourceEntry::where('citable_id', $two->id)->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)->value('source_volume_id'));
        $this->assertTrue(AuditLog::where('auditable_id', $one->id)->where('auditable_type', Manuscript::class)
            ->where('new_values->corpus_fix', 'move-cited-part (step 14.152)')->exists());

        $this->artisan('catalogs:move-cited-part', $args + ['--apply' => true])->expectsOutputToContain('Moved 0 copies.')->assertSuccessful();
    }

    public function test_copies_whose_citation_names_another_book_move_to_its_volume_of_no_library()
    {
        $library = Library::create(['name' => 'غرب', 'city' => 'همدان', 'slug' => 'gharb-mcp-' . uniqid()]);
        $fankha = Catalog::firstOrCreate(['code' => 'fankha'], ['name' => 'فهرستگان نسخه‌های خطی ایران', 'short_name' => 'فنخا']);
        $own = CatalogVolume::create(['title' => 'فهرست مدرسۀ غرب آزمایشی ' . uniqid(), 'volume_number' => 1, 'library_id' => $library->id, 'catalog_id' => $fankha->id]);
        $book = CatalogVolume::create(['title' => 'فهرست رشت و همدان آزمایشی ' . uniqid(), 'volume_number' => 1, 'library_id' => null, 'catalog_id' => $fankha->id]);
        $work = Work::create(['primary_title' => 'رساله', 'clean_title' => 'رساله', 'slug' => 'work-mcp-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = function (string $shelf, string $citation) use ($library, $work, $own) {
            $m = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => $shelf, 'catalog_volume_id' => $own->id,
                'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1, 'metadata' => ['catalog_citation' => $citation]]);

            return $m;
        };
        $named = $copy('12', '[رشت و همدان: ف: - 1338]');
        $dash = $copy('13', '[رشت و همدان - ف: -1395]');
        $plain = $copy('14', '[ف: 1251]');
        SourceEntrySync::syncCited(\App\Support\EntryColumns::query('manuscripts', ['id', 'catalog_citation', 'raw_text', 'catalog_volume_id'])->whereIn('manuscripts.id', [$named->id, $dash->id, $plain->id])->get());
        $args = ['--library' => $library->slug, '--names' => 'رشت و همدان', '--from' => $own->title, '--to' => $book->title, '--apply' => true];

        $this->artisan('catalogs:move-cited-part', $args)->expectsOutputToContain('Moved 2 copies.')->assertSuccessful();
        $this->assertSame([$book->id, $book->id, $own->id], [$named->fresh()->citedEntry?->source_volume_id, $dash->fresh()->citedEntry?->source_volume_id, $plain->fresh()->citedEntry?->source_volume_id]);
        // the copy keeps its library; its cited page is now on the book's volume
        $this->assertSame($library->id, $named->fresh()->library_id);
        $entry = SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $named->id)->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)->sole();
        $this->assertSame([$book->id, 1338], [$entry->source_volume_id, $entry->page_start]);
        $this->assertTrue(AuditLog::where('auditable_id', $dash->id)->where('new_values->corpus_fix', 'move-cited-part --names (step 14.326)')->exists());
        $this->artisan('catalogs:move-cited-part', $args)->expectsOutputToContain('Moved 0 copies.')->assertSuccessful();
    }

    public function test_copies_whose_citation_gives_another_volume_move_to_it()
    {
        $library = Library::create(['name' => 'دائرةالمعارف', 'city' => 'تهران', 'slug' => 'dia-mcp-' . uniqid()]);
        $fankha = Catalog::firstOrCreate(['code' => 'fankha'], ['name' => 'فهرستگان نسخه‌های خطی ایران', 'short_name' => 'فنخا']);
        $volume = fn (int $n, string $title) => CatalogVolume::create(['title' => "فهرست عکسی آزمایشی - {$title}", 'volume_number' => $n,
            'library_id' => $library->id, 'catalog_id' => $fankha->id]);
        $one = $volume(1, 'جلد اول');
        $two = $volume(2, 'جلد دوم');
        $work = Work::create(['primary_title' => 'رساله', 'clean_title' => 'رساله', 'slug' => 'work-mcp-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = function (string $shelf, string $citation) use ($library, $work, $one) {
            $m = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => $shelf, 'catalog_volume_id' => $one->id,
                'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1, 'metadata' => ['catalog_citation' => $citation]]);

            return $m;
        };
        $second = $copy('20', '[عکسی ف: 2 - 57]');
        $first = $copy('21', '[عکسی ف: 1-358]');
        SourceEntrySync::syncCited(\App\Support\EntryColumns::query('manuscripts', ['id', 'catalog_citation', 'raw_text', 'catalog_volume_id'])->whereIn('manuscripts.id', [$second->id, $first->id])->get());
        $args = ['--library' => $library->slug, '--cited-volume' => 2, '--from' => $one->title, '--to' => $two->title, '--apply' => true];

        $this->artisan('catalogs:move-cited-part', $args)->expectsOutputToContain('Moved 1 copies.')->assertSuccessful();
        $this->assertSame([$two->id, $one->id], [$second->fresh()->citedEntry?->source_volume_id, $first->fresh()->citedEntry?->source_volume_id]);
        $entry = SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $second->id)->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)->sole();
        $this->assertSame([$two->id, 57], [$entry->source_volume_id, $entry->page_start]);
        $this->assertTrue(AuditLog::where('auditable_id', $second->id)->where('new_values->corpus_fix', 'move-cited-part --cited-volume (step 14.333)')->exists());
    }

    public function test_it_stops_when_a_volume_is_not_found()
    {
        $this->artisan('catalogs:move-cited-part', ['--library' => 'nowhere-' . uniqid(), '--volume' => 33, '--part' => 1, '--from' => 'a', '--to' => 'b'])
            ->assertFailed();
    }
}
