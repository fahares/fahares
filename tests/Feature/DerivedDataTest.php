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
        DB::table('manuscript_people')->where('role', 'scribe')->whereIn('manuscript_id', $copies)->delete();
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

    public function test_a_copy_date_naming_the_authors_time_is_ranged_from_the_authors_death()
    {
        $author = DB::table('people')->insertGetId(['name' => 'مؤلف آزمایشی بازه‌ی نسبی', 'normalized_name' => 'مولف ازمایشی بازه ی نسبی', 'slug' => 'test-relative-' . uniqid(), 'death_year_hijri' => 1050]);
        try {
            $work = $this->work(['author_id' => $author]);
            // Saved as a model: the hook ranges it
            $copy = Manuscript::find($this->copy($work));
            $copy->update(['copy_date_raw' => 'نزدیک عصر مؤلف']);
            $this->assertSame([1050, 1125, 'probable', 'نزدیک عصر مؤلف'], [$copy->copy_date_from, $copy->copy_date_to, $copy->copy_date_certainty, $copy->copy_date_hedge_raw]);
            // Written with DB::table: refresh-derived finds it and ranges it
            $other = $this->copy($work, ['copy_date_raw' => 'در حیات مؤلف']);
            $this->artisan('fahares:refresh-derived', ['--apply' => true, '--show' => 500])->assertSuccessful();
            $this->assertSame([1000, 1050], [(int) DB::table('manuscripts')->where('id', $other)->value('copy_date_from'), (int) DB::table('manuscripts')->where('id', $other)->value('copy_date_to')]);
        } finally {
            DB::table('works')->whereIn('id', $this->works)->update(['author_id' => null]);
            DB::table('people')->where('id', $author)->delete();
        }
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

    public function test_a_copys_bound_volume_follows_its_shelfmark_and_its_treatises_are_listed_by_leaf()
    {
        $this->assertSame('8740', Manuscript::collectionKeyOf('8740/7-58/190'));
        $this->assertSame('1068-طباطبائی', Manuscript::collectionKeyOf('1068/2-طباطبائی'));
        $this->assertNull(Manuscript::collectionKeyOf('4825'));
        // A mark stuck to the part names a collection; a section, a second shelfmark or a margin note does not
        $this->assertSame('622-ط', Manuscript::collectionKeyOf('622/1ط'));
        $this->assertSame('1359-عکسی', Manuscript::collectionKeyOf('1359/9عکسی'));
        $this->assertSame('149-معزی', Manuscript::collectionKeyOf('149/1 معزی'));
        $this->assertSame('444-کر', Manuscript::collectionKeyOf('444/1؛ کر'));
        $this->assertSame('3753-ف', Manuscript::collectionKeyOf('3753/2 ـ ف'));
        $this->assertSame('1051-طباطبائی', Manuscript::collectionKeyOf('1051/2-ط'));
        $this->assertSame(['262', '262', '3008', '1440'], [Manuscript::collectionKeyOf('262/1بخش3'), Manuscript::collectionKeyOf('262/2 بخش 2'),
            Manuscript::collectionKeyOf('3008/18 حاشیه'), Manuscript::collectionKeyOf('1440/1 و 1440/2')]);
        $this->assertSame('1', Manuscript::collectionKey('622/1ط')['part']);
        // A three-part shelfmark with a short first number is bound by its first two numbers; a long first number keeps its volume
        $this->assertSame(['1/2591', '1/534', '4/120', '2267'], [Manuscript::collectionKeyOf('1/2591/104'), Manuscript::collectionKeyOf('1/534/9'),
            Manuscript::collectionKeyOf('4/120/1-5957'), Manuscript::collectionKeyOf('2267/6/10')]);
        $this->assertSame('104', Manuscript::collectionKey('1/2591/104')['part']);
        // ...but in Ehya «عکسی» only says the treatise is a photocopy, one of its volume's
        $this->assertSame('1004', Manuscript::collectionKeyOf('1004/7 عکسی', "قم؛ مرکز احیاء؛ شماره نسخه: 1004/7 عکسی\nنسخه اصل: همان نسخه بالا"));
        $this->assertSame('1390-عکسی', Manuscript::collectionKeyOf('1390/1عکسی', '1. قم؛ مرعشی؛ شماره نسخه: 1390/1عکسی'));
        // A Majlis copy described only from the Senate library's catalogue has the Senate's numbering
        $this->assertSame('791-سنا', Manuscript::collectionKeyOf('791/1', "تهران؛ مجلس؛ شماره نسخه: 791/1\n38ص (1-38) [سنا: ف: 2-61]"));
        $this->assertSame('149-سنا', Manuscript::collectionKeyOf('149/30', 'تهران؛ مجلس؛ شماره نسخه: 149/30 [سنا - ف: 1-75]'));
        $this->assertSame('791', Manuscript::collectionKeyOf('791/1', 'تهران؛ مجلس؛ شماره نسخه: 791/1 [ف: 3-6]'));

        // Saved as a model: the hook keys it, and a corrected shelfmark moves it
        $work = $this->work();
        $model = Manuscript::find($this->copy($work));
        $model->update(['shelfmark' => '9101/2']);
        $this->assertSame('9101', $model->collection_key);
        $model->update(['shelfmark' => '9102/2']);
        $this->assertSame('9102', $model->fresh()->collection_key);

        // Written with DB::table: the fillers key them; part 2 has no range and keeps its place, 3 and 4 swap by leaf
        $lib = 'کتابخانه‌ی آزمایشی مجموعه ' . uniqid();
        $part = fn (string $shelfmark, ?array $range = null, array $columns = []) => $this->copy($this->work(['primary_title' => "رساله‌ی {$shelfmark}"]), $columns + [
            'library' => $lib, 'shelfmark' => $shelfmark, 'shelfmark_key' => $shelfmark, 'folios' => $range ? $range[3] - $range[2] + 1 : null,
            'range_from' => $range[0] ?? null, 'range_to' => $range[1] ?? null, 'range_from_number' => $range[2] ?? null, 'range_to_number' => $range[3] ?? null]);
        $one = $part('9101/1', ['1پ', '33ر', 1, 33]);
        $two = $part('9101/2');
        $three = $part('9101/3', ['55ر', '60پ', 55, 60]);
        $four = $part('9101/4', ['34پ', '43ر', 34, 43]);
        $other = $part('9101/5-طباطبائی', ['1', '9', 1, 9]);
        $senate = $part('9101/6', ['70', '80', 70, 80], ['raw_text' => 'تهران؛ مجلس؛ شماره نسخه: 9101/6 [سنا: ف: 2-61]']);
        DB::table('manuscripts')->where('id', $three)->update(['raw_text' => "تهران؛ مجلس؛ شماره نسخه: 9101/3\nنستعلیق، بی‌کا؛ در هامش؛ 6گ (55ر-60پ)"]);

        // Not keyed yet (the time between a deploy and the filler): the volume is found by the shelfmark's number
        $this->assertSame([$one, $two, $four, $three], Manuscript::find($four)->collectionSiblings()->pluck('id')->all());
        $this->artisan('fahares:fill-collection-keys')->expectsOutputToContain("#{$four} «9101/4»: — → 9101")->expectsOutputToContain('Dry run: nothing written.')->assertSuccessful();
        $this->assertNull(DB::table('manuscripts')->where('id', $four)->value('collection_key'));
        $this->artisan('fahares:fill-collection-keys', ['--apply' => true])->assertSuccessful();
        $this->assertSame('9101-طباطبائی', DB::table('manuscripts')->where('id', $other)->value('collection_key'));
        $this->assertSame('9101-سنا', DB::table('manuscripts')->where('id', $senate)->value('collection_key'));

        $this->assertSame([$one, $two, $four, $three], Manuscript::find($four)->collectionSiblings()->pluck('id')->all());
        $this->get(route('manuscripts.show', Manuscript::find($four)))->assertOk()
            ->assertSee('برگ‌های ۳۴پ تا ۴۳ر، همراه با این رساله‌ها (به ترتیب برگ)', false)
            ->assertSeeInOrder(['رساله‌ی 9101/1', 'رساله‌ی 9101/2', 'رساله‌ی 9101/4', 'رساله‌ی 9101/3'])
            ->assertSee('(در حاشیه)')
            ->assertDontSee('رساله‌ی 9101/5-طباطبائی')
            ->assertDontSee('رساله‌ی 9101/6');
        $this->assertTrue(Manuscript::find($three)->inMargin());
        $this->assertFalse(Manuscript::find($one)->inMargin());
        $this->assertFalse((new Manuscript(['raw_text' => 'خط: نستعلیق؛ ابیاتی در هامش نوشته شده؛ 3گ']))->inMargin());

        // A shelfmark a corpus command rewrote: refresh-derived reports it and moves the key; one a reviewer set is kept
        DB::table('manuscripts')->where('id', $two)->update(['shelfmark' => '9103/2']);
        DB::table('manuscripts')->where('id', $three)->update(['collection_key' => '9101-دستی']);
        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $three, 'action' => 'verify', 'old_values' => [], 'new_values' => ['collection_key' => '9101-دستی', 'direct_edit' => true]]);
        file_put_contents($this->file, json_encode(['works' => []]));
        $this->artisan('fahares:refresh-derived', ['--show' => 500])->expectsOutputToContain("#{$two} «9103/2»: 9101 → 9103")->assertSuccessful();
        $this->artisan('fahares:refresh-derived', ['--apply' => true, '--show' => 500, '--excerpts' => $this->file, '--deaths' => $this->file])->assertSuccessful();
        $this->assertSame(['9103', '9101-دستی'], [DB::table('manuscripts')->where('id', $two)->value('collection_key'), DB::table('manuscripts')->where('id', $three)->value('collection_key')]);
    }
}
