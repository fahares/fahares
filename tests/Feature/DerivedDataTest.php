<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\TextExcerpt;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The corpus apply commands keep derived data in step, and fahares:refresh-derived reports what has drifted (item 87). */
class DerivedDataTest extends TestCase
{
    private array $works = [];

    private array $manuscripts = [];

    private string $file;

    private ?string $dir = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'derived');
    }

    protected function tearDown(): void
    {
        $works = array_merge($this->works, Work::where('slug', 'like', '%-v99-9101')->pluck('id')->all());
        $copies = array_merge($this->manuscripts, Manuscript::whereIn('work_id', $works)->pluck('id')->all());
        AuditLog::whereIn('auditable_type', [Work::class, Manuscript::class])->whereIn('auditable_id', array_merge($works, $copies))->delete();
        DB::table('source_entries')->whereIn('citable_type', [Work::class, Manuscript::class])->whereIn('citable_id', array_merge($works, $copies))->delete();
        TextExcerpt::where('owner_type', Manuscript::class)->whereIn('owner_id', $copies)->delete();
        TextExcerpt::where('owner_type', Work::class)->whereIn('owner_id', $works)->delete();
        DB::table('work_people')->whereIn('work_id', $works)->delete();
        DB::table('manuscript_scribe')->whereIn('manuscript_id', $copies)->delete();
        DB::table('manuscripts')->whereIn('id', $copies)->delete();
        DB::table('works')->whereIn('id', $works)->delete();
        @unlink($this->file);
        if ($this->dir) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    private function work(array $columns = []): int
    {
        return $this->works[] = DB::table('works')->insertGetId($columns + [
            'primary_title' => 'اثر آزمایشی مشتق', 'clean_title' => 'اثر آزمایشی مشتق',
            'slug' => 'test-derived-' . uniqid(), 'volume_number' => 99, 'page_start' => 1,
        ]);
    }

    private function copy(int $work, array $columns = []): int
    {
        return $this->manuscripts[] = DB::table('manuscripts')->insertGetId($columns + [
            'work_id' => $work, 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1,
            'city' => 'تهران', 'library' => 'دانشگاه', 'shelfmark' => 'آز-' . uniqid(), 'raw_text' => 'تهران؛ دانشگاه',
        ]);
    }

    public function test_rebuild_copies_brings_the_date_range_extent_and_excerpt_pointer_in_step()
    {
        $work = $this->work();
        $second = TextExcerpt::create(['owner_type' => Work::class, 'owner_id' => $work, 'kind' => 'incipit', 'n' => 2, 'text' => 'آغاز دوم', 'position' => 2]);
        $id = $this->copy($work, ['raw_text' => 'متن همسایه', 'copy_date_raw' => '900ق', 'copy_date_from' => 900, 'copy_date_to' => 900,
            'folios' => 5, 'extent_raw' => '5گ', 'incipit_text' => 'آغاز همسایه']);
        $raw = "تهران؛ دانشگاه؛ شماره نسخه: 1\nنسخ، 1100ق؛ 12گ (1پ-12پ)، 15 سطر";
        file_put_contents($this->file, json_encode(['records' => [[
            'table' => 'manuscripts', 'id' => $id, 'shelfmark' => DB::table('manuscripts')->where('id', $id)->value('shelfmark'), 'category' => 'rebuild', 'v2' => 'v99-1#1',
            'changes' => [
                'raw_text' => ['from' => 'متن همسایه', 'to' => $raw],
                'copy_date_raw' => ['from' => '900ق', 'to' => '1100ق'],
                'incipit_text' => ['from' => 'آغاز همسایه', 'to' => 'برابر 2'],
            ],
        ]]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $row = DB::table('manuscripts')->where('id', $id)->first();
        $this->assertSame([1100, 1100], [(int) $row->copy_date_from, (int) $row->copy_date_to]);
        $this->assertSame([12, '12گ (1پ-12پ)', '1پ', '12پ'], [(int) $row->folios, $row->extent_raw, $row->range_from, $row->range_to]);
        $pointer = TextExcerpt::where('owner_type', Manuscript::class)->where('owner_id', $id)->sole();
        $this->assertSame([$second->id, 'برابر 2'], [$pointer->same_as_excerpt_id, $pointer->same_as_raw]);
        // The leaf count changed, and is logged as fill-extents logs it
        $this->assertTrue(AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $id)->where('new_values->corpus_fix', 'derived data')->exists());
    }

    public function test_add_corpus_works_gives_new_works_their_dedicatees_and_new_copies_their_extent()
    {
        $columns = ['primary_title' => 'اثر آزمایشی تازه', 'clean_title' => 'اثر آزمایشی تازه', 'alternative_titles' => null,
            'transliteration' => 'asar-e taze', 'author_name' => null, 'composition_date_raw' => '1250ق',
            'composition_place' => null, 'work_form' => null, 'subject_summary' => null, 'language_summary' => null,
            'incipit_text' => null, 'explicit_text' => null, 'description' => null, 'bibliography' => null,
            'print_info' => null, 'dedication' => 'به نام میرزا آزمایشی نامدار', 'related_work' => null, 'commentaries_and_glosses' => null,
            'raw_text' => '● اثر آزمایشی تازه', 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1];
        $make = ['sequence_number' => 1, 'page_start' => 1, 'page_end' => 1, 'city' => 'تهران', 'library' => 'ملی آزمایشی',
            'shelfmark' => '91/1', 'raw_text' => "تهران؛ ملی آزمایشی؛ شماره نسخه: 91/1\nنسخ، بی‌تا؛ 40ص (3-42) [ف: 1-2]",
            'scribe_name' => null, 'has_other_scribes' => 0, 'is_bika' => 1, 'is_bita' => 1, 'is_autograph' => 0,
            'copy_date_raw' => null, 'copy_date_hijri_year' => null, 'copy_place' => null, 'commissioned_by' => null,
            'folios' => null, 'lines' => null, 'dimensions' => null, 'paper' => null, 'binding' => null,
            'incipit_text' => null, 'explicit_text' => null, 'is_corrected' => 0, 'has_marginal_notes' => 0,
            'is_ruled' => 0, 'has_catchwords' => 0, 'is_facsimile' => 0, 'is_collated' => 0, 'is_illuminated' => 0,
            'is_illustrated' => 0, 'has_author_marginalia' => 0, 'metadata' => ['catalog_citation' => '[ف: 1-2]']];
        file_put_contents($this->file, json_encode(['works' => [[
            'v2' => 'v99-1', 'slug_suffix' => '-v99-9101', 'columns' => $columns, 'author' => null, 'roles' => [],
            'subjects' => [], 'languages' => [], 'copies' => [['make' => $make]],
        ]], 'fills' => [], 'work_updates' => []], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:add-corpus-works', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $work = Work::where('slug', 'like', '%-v99-9101')->sole();
        $this->assertSame([1250, 1250], [$work->composition_date_from, $work->composition_date_to]);
        $row = DB::table('work_people')->where('work_id', $work->id)->sole();
        $this->assertSame(['dedicatee', 'میرزا آزمایشی نامدار'], [$row->role, $row->name ?? DB::table('people')->where('id', $row->person_id)->value('name')]);
        $copy = DB::table('manuscripts')->where('work_id', $work->id)->sole();
        $this->assertSame([null, 40, '3', '42'], [$copy->folios, (int) $copy->pages, $copy->range_from, $copy->range_to]);
    }

    public function test_sync_work_details_gives_a_changed_dedication_its_people()
    {
        $work = $this->work(['slug' => 'test-derived-v99-1', 'dedication' => null]);
        $this->dir = sys_get_temp_dir() . '/derived-' . uniqid();
        File::makeDirectory($this->dir);
        file_put_contents("{$this->dir}/fankha_vol_99.json", json_encode(['works' => [
            ['raw_text' => '● اثر آزمایشی مشتق', 'dedication' => 'به درخواست حاجی آزمایشی فرمایشگر', 'page_start' => 1],
        ]], JSON_UNESCAPED_UNICODE));
        // Only this work of the volume: other tests' works of volume 99 are gone by now
        $this->assertSame([$work], DB::table('works')->where('volume_number', 99)->pluck('id')->all());

        $this->artisan('fahares:sync-work-details', ['--source' => $this->dir, '--volume' => 99, '--skip-manuscripts' => true])->assertSuccessful();

        $row = DB::table('work_people')->where('work_id', $work)->sole();
        $this->assertSame(['commissioner', 'حاجی آزمایشی فرمایشگر'], [$row->role, $row->name]);
    }

    public function test_refresh_derived_reports_drift_and_with_apply_mends_it_but_keeps_rows_the_source_no_longer_gives()
    {
        $work = $this->work(['dedication' => 'به نام امیر آزمایشی تازه', 'composition_date_raw' => '1200ق']);
        $stale = $this->copy($work, ['copy_date_raw' => '1100ق', 'copy_date_from' => 900, 'copy_date_to' => 900, 'raw_text' => "تهران؛ دانشگاه\nنسخ؛ 30گ"]);
        $cleared = $this->copy($work, ['copy_date_raw' => null, 'copy_date_from' => 900, 'copy_date_to' => 900]);
        // A pointer whose copy's incipit no longer is one
        $excerpt = TextExcerpt::create(['owner_type' => Work::class, 'owner_id' => $work, 'kind' => 'incipit', 'n' => 2, 'text' => 'آغاز دوم', 'position' => 2]);
        TextExcerpt::create(['owner_type' => Manuscript::class, 'owner_id' => $cleared, 'kind' => 'incipit', 'same_as_excerpt_id' => $excerpt->id, 'same_as_raw' => 'برابر 2']);
        $orphan = DB::table('work_people')->insertGetId(['work_id' => $work, 'role' => 'dedicatee', 'name' => 'امیر پیشین آزمایشی', 'position' => 0]);

        $this->artisan('fahares:refresh-derived', ['--show' => 500])
            ->expectsOutputToContain("#{$stale} «1100ق»: 900–900 → 1100–1100")
            ->expectsOutputToContain("#{$cleared} «»: 900–900 → –")
            ->expectsOutputToContain("#{$stale} «» —گ/—ص → «30گ» 30گ/—ص")
            ->expectsOutputToContain("#{$work} «1200ق»: – → 1200–1200")
            ->expectsOutputToContain("#{$work} dedicatee: امیر آزمایشی تازه")
            ->expectsOutputToContain("work_people #{$orphan} (work #{$work}) dedicatee: امیر پیشین آزمایشی")
            ->expectsOutputToContain("#{$cleared} incipit: «برابر 2» → #{$excerpt->id} ≠ none")
            ->expectsOutputToContain('Dry run: nothing written.')
            ->assertSuccessful();
        $this->assertSame(900, (int) DB::table('manuscripts')->where('id', $stale)->value('copy_date_from'));

        // Small decision files: the real ones (9.6 MB) keep the test process above the exports' memory limit
        file_put_contents($this->file, json_encode(['works' => []]));
        $this->artisan('fahares:refresh-derived', ['--apply' => true, '--show' => 500, '--excerpts' => $this->file, '--deaths' => $this->file])
            ->expectsOutputToContain("work_people #{$orphan} (work #{$work}) dedicatee: امیر پیشین آزمایشی")
            ->assertSuccessful();

        $this->assertSame(1100, (int) DB::table('manuscripts')->where('id', $stale)->value('copy_date_from'));
        $this->assertSame(30, (int) DB::table('manuscripts')->where('id', $stale)->value('folios'));
        $this->assertNull(DB::table('manuscripts')->where('id', $cleared)->value('copy_date_from'));
        $this->assertSame(1200, (int) DB::table('works')->where('id', $work)->value('composition_date_from'));
        $this->assertFalse(TextExcerpt::where('owner_type', Manuscript::class)->where('owner_id', $cleared)->exists());
        $this->assertSame(['امیر آزمایشی تازه', 'امیر پیشین آزمایشی'], DB::table('work_people')->where('work_id', $work)->orderBy('name')->pluck('name')->all());
    }
}
