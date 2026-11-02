<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Sources\SourceEntrySync;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * fahares:fix-reversed-citations (step 14.12): a citation Fankha printed «page-volume» is put back as the corpus
 * now has it, its entry takes the volume and page, a copy goes to the volume it names; a backup puts it all back.
 */
class FixReversedCitationsTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-frc-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function volume(Source $source, int $number): SourceVolume
    {
        $columns = ['title' => $source->title . ' - ' . $number, 'volume_number' => $number];
        $cv = CatalogVolume::create($columns);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], $columns + ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function copy(Library $library, string $shelfmark, string $citation, ?SourceVolume $volume): Manuscript
    {
        $copy = Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library->id, 'shelfmark' => $shelfmark, 'catalog_volume_id' => $volume?->id,
            'volume_number' => 4, 'page_start' => 1, 'raw_text' => "1. تهران؛ نوربخش؛ شماره نسخه: {$shelfmark}\nخط: نستعلیق، بی‌کا {$citation}", 'metadata' => ['catalog_citation' => $citation]]);
        $parsed = \App\Console\Commands\ImportFankhaCatalogReferencesCommand::parse($citation);
        DB::table('manuscript_catalog_references')->insert(['manuscript_id' => $copy->id, 'catalog_volume_id' => $volume?->id, 'volume_number' => $parsed['volume'],
            'page_start' => $parsed['page'], 'raw_text' => $citation, 'source' => 'fankha', 'status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
        SourceEntrySync::syncCited(DB::table('manuscripts')->where('id', $copy->id)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));

        return $copy;
    }

    private function record(Manuscript $copy, string $from, string $to): array
    {
        $raw = DB::table('manuscripts')->where('id', $copy->id)->value('raw_text');

        return ['id' => $copy->id, 'volume' => 4, 'shelfmark' => $copy->shelfmark, 'changes' => [
            'catalog_citation' => ['from' => $from, 'to' => $to],
            'raw_text' => ['from' => $raw, 'to' => str_replace($from, $to, $raw)],
        ]];
    }

    private function state(): array
    {
        $rows = fn ($table, $columns) => DB::table($table)->orderBy('id')->get($columns)->map(fn ($r) => (array) $r)->all();

        return [
            'copies' => $rows('manuscripts', ['id', 'raw_text', 'metadata', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']),
            'references' => $rows('manuscript_catalog_references', ['id', 'raw_text', 'volume_number', 'page_start', 'catalog_volume_id']),
            'entries' => $rows('source_entries', ['id', 'citation_text', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'volumes' => $rows('catalog_volumes', ['id', 'manuscripts_count']),
            'catalogers' => $rows('catalogers', ['id', 'volumes_count', 'manuscripts_count', 'supervised_count']),
            'audit' => DB::table('audit_logs')->count(),
        ];
    }

    public function test_the_citation_is_put_back_its_copy_moved_and_revert()
    {
        $library = Library::create(['name' => 'نوربخش', 'city' => 'تهران', 'slug' => 'thran-norbkhsh-1']);
        $first = Cataloger::create(['name' => 'فهرست‌نگار یکم', 'slug' => 'frc-first']);
        $second = Cataloger::create(['name' => 'فهرست‌نگار دوم', 'slug' => 'frc-second']);
        $source = Source::create(['code' => 'catalogue-frc', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست نوربخش', 'library_id' => $library->id]);
        $one = $this->volume($source, 1);
        $two = $this->volume($source, 2);
        $one->catalogers()->attach([$first->id => ['role' => 'cataloger']]);
        $two->catalogers()->attach([$second->id => ['role' => 'cataloger']]);

        $onFirst = $this->copy($library, '590/5', '[ف: 236-2]', $one);
        $right = $this->copy($library, '12', '[ف: 40-1]', $one);
        $edited = $this->copy($library, '13', '[ف: 41-1]', $one);
        DB::table('manuscripts')->where('id', $onFirst->id)->update(['cataloger_id' => $first->id]);
        $records = [$this->record($onFirst, '[ف: 236-2]', '[ف: 2-236]'), $this->record($right, '[ف: 40-1]', '[ف: 1-40]'),
            $this->record($edited, '[ف: 41-1]', '[ف: 1-41]')];
        // edited on the site since the export: left
        DB::table('manuscripts')->where('id', $edited->id)->update(['raw_text' => 'ویرایش شده']);
        $file = storage_path('framework/testing/reversed-' . uniqid() . '.json');
        file_put_contents($file, json_encode(['records' => $records], JSON_UNESCAPED_UNICODE));

        $before = $this->state();
        $this->artisan('fahares:fix-reversed-citations', ['--file' => $file])->assertSuccessful()->expectsOutput('Would write 2 copies.');
        $this->assertSame($before, $this->state(), 'without --apply nothing is saved');

        $backup = storage_path('framework/testing/reversed-backup-' . uniqid() . '.json');
        $this->artisan('fahares:fix-reversed-citations', ['--file' => $file, '--apply' => true, '--backup' => $backup])->assertSuccessful();

        $copy = DB::table('manuscripts')->where('id', $onFirst->id)->first();
        $this->assertSame('[ف: 2-236]', json_decode($copy->metadata, true)['catalog_citation']);
        $this->assertStringEndsWith('[ف: 2-236]', $copy->raw_text);
        $this->assertSame([$two->id, $second->id], [(int) $copy->catalog_volume_id, (int) $copy->cataloger_id], 'the copy goes to the volume it names, and its cataloger');
        $this->assertEquals([$source->id, $two->id, 2, 236], array_values((array) DB::table('source_entries')->where('legacy_type', 'fankha_citation')->where('legacy_id', $onFirst->id)
            ->first(['source_id', 'source_volume_id', 'volume_number', 'page_start'])));
        $this->assertEquals(['[ف: 2-236]', 2, 236, $two->id], array_values((array) DB::table('manuscript_catalog_references')->where('manuscript_id', $onFirst->id)
            ->first(['raw_text', 'volume_number', 'page_start', 'catalog_volume_id'])));
        $this->assertSame($one->id, (int) DB::table('manuscripts')->where('id', $right->id)->value('catalog_volume_id'));
        $this->assertEquals([1, 40], array_values((array) DB::table('source_entries')->where('legacy_type', 'fankha_citation')->where('legacy_id', $right->id)->first(['volume_number', 'page_start'])));
        $this->assertSame('ویرایش شده', DB::table('manuscripts')->where('id', $edited->id)->value('raw_text'));
        $this->assertSame([2, 1], [DB::table('catalog_volumes')->where('id', $one->id)->value('manuscripts_count'), DB::table('catalog_volumes')->where('id', $two->id)->value('manuscripts_count')]);
        $this->assertSame(1, $second->fresh()->manuscripts_count);

        $this->artisan('fahares:fix-reversed-citations', ['--file' => $file])->assertSuccessful()->expectsOutput('Would write 0 copies.');

        $this->artisan('fahares:fix-reversed-citations', ['--revert' => $backup])->assertSuccessful();
        $this->assertSame($before, $this->state(), 'the revert puts back every row');
    }
}
