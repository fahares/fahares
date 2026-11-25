<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use App\Sources\RecordEntries;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A copy's catalogue citation is its citation entry's, not its metadata's (step 14.413, backlog item 170). */
class CitationEntryTest extends TestCase
{
    private Library $library;

    private CatalogVolume $volume;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'citation-entry-' . uniqid()]);
        $fankha = Catalog::firstOrCreate(['code' => 'fankha'], ['name' => 'فهرستگان نسخه‌های خطی ایران', 'short_name' => 'فنخا']);
        $this->volume = CatalogVolume::create(['title' => 'فهرست مسجد اعظم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id, 'catalog_id' => $fankha->id]);
    }

    private function copy(array $attributes): Manuscript
    {
        $work = Work::create(['primary_title' => 'دیوان', 'clean_title' => 'دیوان', 'slug' => 'citation-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create($attributes + ['work_id' => $work->id, 'library_id' => $this->library->id, 'shelfmark' => '1',
            'catalog_volume_id' => $this->volume->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1]);
    }

    private function entry(Manuscript $copy): object
    {
        return DB::table('source_entries')->where('legacy_type', 'fankha_citation')->where('legacy_id', $copy->id)->where('legacy_index', 0)->first();
    }

    public function test_a_citation_given_in_the_metadata_goes_to_the_entry()
    {
        $copy = $this->copy(['metadata' => ['catalog_citation' => '[ف: 2-414]', 'residual_notes' => 'یادداشت']]);

        $this->assertSame('[ف: 2-414]', $this->entry($copy)->citation_text);
        $this->assertSame([2, 414], [(int) $this->entry($copy)->volume_number, (int) $this->entry($copy)->page_start]);
        $this->assertSame(['residual_notes' => 'یادداشت'], json_decode(DB::table('manuscripts')->where('id', $copy->id)->value('metadata'), true));
        $this->assertSame('[ف: 2-414]', $copy->fresh()->catalog_citation);

        // a new citation, by the metadata or by its own name; the rest of the metadata stays
        $copy->update(['metadata' => ['catalog_citation' => '[ف: 2-500]', 'residual_notes' => 'یادداشت']]);
        $this->assertSame(['[ف: 2-500]', 500], [$this->entry($copy)->citation_text, (int) $this->entry($copy)->page_start]);
        $copy->fresh()->update(['catalog_citation' => '[ف: 2-501]']);
        $this->assertSame('[ف: 2-501]', $this->entry($copy)->citation_text);
        $this->assertSame(['residual_notes' => 'یادداشت'], $copy->fresh()->metadata);

        // the text alone changes nothing: the entry keeps its citation
        $copy->fresh()->update(['raw_text' => 'متن تازه [ف: 2-9]']);
        $this->assertSame('[ف: 2-501]', $this->entry($copy)->citation_text);

        // a metadata that held only the citation is none
        $only = $this->copy(['metadata' => ['catalog_citation' => '[ف: 2-7]'], 'shelfmark' => '2']);
        $this->assertNull(DB::table('manuscripts')->where('id', $only->id)->value('metadata'));
    }

    public function test_a_query_builder_write_and_a_restored_row_lift_it_too()
    {
        $copy = $this->copy(['metadata' => ['residual_notes' => 'یادداشت']]);
        RecordEntries::write($copy, ['metadata' => json_encode(['catalog_citation' => '[ف: 2-88]', 'residual_notes' => 'یادداشت'], JSON_UNESCAPED_UNICODE)]);
        $this->assertSame('[ف: 2-88]', $this->entry($copy)->citation_text);
        $this->assertSame(['residual_notes' => 'یادداشت'], json_decode(DB::table('manuscripts')->where('id', $copy->id)->value('metadata'), true));

        $this->assertSame(['metadata' => '{"a":1}', 'catalog_citation' => null], RecordEntries::liftCitation(['metadata' => '{"a":1,"catalog_citation":null}']));
        $this->assertSame(['metadata' => ['a' => 1]], RecordEntries::liftCitation(['metadata' => ['a' => 1]]));
    }

    public function test_the_command_takes_the_key_out_only_where_the_entry_holds_it()
    {
        $same = $this->copy(['shelfmark' => '1']);
        $empty = $this->copy(['shelfmark' => '2']);
        $other = $this->copy(['shelfmark' => '3', 'metadata' => ['catalog_citation' => '[ف: 2-30]']]);
        // as production's copies hold it before the command: the key beside the entry
        DB::table('manuscripts')->where('id', $same->id)->update(['metadata' => json_encode(['catalog_citation' => '[ف: 2-10]', 'residual_notes' => 'x'], JSON_UNESCAPED_UNICODE)]);
        DB::table('source_entries')->where('legacy_type', 'fankha_citation')->where('legacy_id', $same->id)->update(['citation_text' => '[ف: 2-10]']);
        DB::table('manuscripts')->where('id', $empty->id)->update(['metadata' => '{"catalog_citation": null}']);
        DB::table('manuscripts')->where('id', $other->id)->update(['metadata' => json_encode(['catalog_citation' => '[ف: 2-99]'], JSON_UNESCAPED_UNICODE)]);

        $this->artisan('sources:drop-citation-key', ['--dry-run' => true])->expectsOutputToContain('Would remove 2 keys.')->assertSuccessful();
        $this->assertNotNull(DB::table('manuscripts')->where('id', $empty->id)->value('metadata'));

        $backup = sys_get_temp_dir() . '/citation-key-' . uniqid() . '.jsonl';
        $this->artisan('sources:drop-citation-key', ['--backup' => $backup])
            ->expectsOutputToContain('Removed 2 keys.')->expectsOutputToContain('1 copies keep the key')->assertSuccessful();
        $lines = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($backup))));
        @unlink($backup);
        $this->assertEqualsCanonicalizing([['id' => $same->id, 'catalog_citation' => '[ف: 2-10]'], ['id' => $empty->id, 'catalog_citation' => null]], $lines);

        $this->assertSame(['residual_notes' => 'x'], json_decode(DB::table('manuscripts')->where('id', $same->id)->value('metadata'), true));
        $this->assertNull(DB::table('manuscripts')->where('id', $empty->id)->value('metadata'));
        $this->assertSame(['catalog_citation' => '[ف: 2-99]'], json_decode(DB::table('manuscripts')->where('id', $other->id)->value('metadata'), true));
        $this->artisan('sources:drop-citation-key')->expectsOutputToContain('Removed 0 keys.')->assertSuccessful();
    }
}
