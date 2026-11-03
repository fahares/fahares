<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddCorpusWorksCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'addworks');
    }

    protected function tearDown(): void
    {
        $works = array_merge($this->works, Work::where('slug', 'like', '%-v99-9001')->pluck('id')->all());
        $copies = array_merge($this->manuscripts, Manuscript::whereIn('work_id', $works)->pluck('id')->all());
        AuditLog::whereIn('auditable_type', [Work::class, Manuscript::class])->whereIn('auditable_id', array_merge($works, $copies))->delete();
        DB::table('source_entries')->whereIn('citable_type', [Work::class, Manuscript::class])->whereIn('citable_id', array_merge($works, $copies))->delete();
        DB::table('work_people')->whereIn('work_id', $works)->delete();
        DB::table('manuscripts')->whereIn('id', $copies)->delete();
        DB::table('works')->whereIn('id', $works)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function work(string $title, string $slug, ?string $author = null): int
    {
        return $this->works[] = DB::table('works')->insertGetId([
            'primary_title' => $title, 'clean_title' => $title, 'slug' => $slug, 'author_name' => $author,
            'volume_number' => 99, 'page_start' => 10, 'page_end' => 10,
        ]);
    }

    private function copy(int $work, string $raw, string $city, string $library, string $shelfmark): int
    {
        return $this->manuscripts[] = DB::table('manuscripts')->insertGetId([
            'work_id' => $work, 'volume_number' => 99, 'page_start' => 10, 'page_end' => 10,
            'city' => $city, 'library' => $library, 'shelfmark' => $shelfmark, 'raw_text' => $raw,
        ]);
    }

    private function make(string $shelfmark, int $seq): array
    {
        return ['make' => [
            'sequence_number' => $seq, 'page_start' => 10, 'page_end' => 10, 'city' => 'تهران', 'library' => 'ملی',
            'shelfmark' => $shelfmark, 'raw_text' => "تهران؛ ملی؛ شماره نسخه: {$shelfmark}\nبی‌کا، بی‌تا [ف: 1-2]",
            'scribe_name' => null, 'has_other_scribes' => 0, 'is_bika' => 1, 'is_bita' => 1, 'is_autograph' => 0,
            'copy_date_raw' => null, 'copy_date_hijri_year' => null, 'copy_place' => null, 'commissioned_by' => null,
            'folios' => 12, 'lines' => null, 'dimensions' => null, 'paper' => null, 'binding' => null,
            'incipit_text' => null, 'explicit_text' => null, 'is_corrected' => 0, 'has_marginal_notes' => 0,
            'is_ruled' => 0, 'has_catchwords' => 0, 'is_facsimile' => 0, 'is_collated' => 0, 'is_illuminated' => 0,
            'is_illustrated' => 0, 'has_author_marginalia' => 0,
            'metadata' => ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [],
                'catalog_citation' => '[ف: 1-2]', 'residual_notes' => null],
        ]];
    }

    public function test_adds_a_work_with_moved_and_made_copies_fills_the_emptied_work_and_runs_once()
    {
        // the old parser gave entry A the next entry's copy and dropped A's own
        $a = $this->work('اثر آزمایشی الف', 'test-alef-v99-9000', 'مؤلف نادرست');
        $wrong = $this->copy($a, "قم؛ مرعشی؛ شماره نسخه: 77/1", 'قم', 'آیت الله مرعشی', '77/1');
        // a copy elsewhere tells which library record «تهران؛ ملی» is on the site now
        $other = $this->work('اثر دیگر', 'test-other-v99-8999');
        $this->copy($other, "1. تهران؛ ملی؛ شماره نسخه: 5", 'تهران', 'کتابخانه ملی', '5');

        $columns = ['primary_title' => 'اثر آزمایشی ب (ترجمه)', 'clean_title' => 'اثر آزمایشی ب', 'alternative_titles' => null,
            'transliteration' => 'asar-e azmayeshi-ye be (t.)', 'author_name' => 'مترجم آزمایشی', 'composition_date_raw' => null,
            'composition_place' => null, 'work_form' => 'translation', 'subject_summary' => 'ادبیات', 'language_summary' => 'فارسی',
            'incipit_text' => null, 'explicit_text' => null, 'description' => null, 'bibliography' => ['الذریعه 1/2'],
            'print_info' => null, 'dedication' => null, 'related_work' => null, 'commentaries_and_glosses' => null,
            'raw_text' => '● اثر آزمایشی ب (ترجمه) / ادبیات / فارسی', 'volume_number' => 99, 'page_start' => 10, 'page_end' => 10];
        file_put_contents($this->file, json_encode([
            'works' => [[
                'v2' => 'v99-2', 'slug_suffix' => '-v99-9001', 'columns' => $columns,
                'author' => ['name' => 'مترجم آزمایشی', 'hedge' => null, 'century' => null, 'death_year' => null],
                'roles' => [['role' => 'translator', 'name' => 'مترجم دیگر آزمایشی', 'hedge' => 'گویا', 'century' => null]],
                'subjects' => [], 'languages' => [],
                'copies' => [
                    ['move' => ['id' => 0, 'from_slug' => 'test-alef-v99-9000', 'city' => 'قم', 'library' => 'آیت الله مرعشی', 'shelfmark' => '77/1'], 'sequence_number' => 1],
                    $this->make('88/2', 2),
                ],
            ]],
            'fills' => [['v2' => 'v99-1', 'work_slug' => 'test-alef-v99-9000', 'copies' => [$this->make('66/3', 1)]]],
            'work_updates' => [['v2' => 'v99-1', 'work_slug' => 'test-alef-v99-9000', 'author' => null,
                'changes' => ['author_name' => ['from' => 'مؤلف نادرست', 'to' => 'مؤلف درست']]]],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:add-corpus-works', ['--file' => $this->file])
            ->expectsOutputToContain('Would add 1 works, fill 1 works and update 1; copies: 1 moved, 2 made.')
            ->assertSuccessful();
        $this->artisan('fahares:add-corpus-works', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $work = Work::where('slug', 'asar-e-azmayeshi-ye-be-t-v99-9001')->sole();
        $this->assertSame('اثر آزمایشی ب (ترجمه)', $work->primary_title);
        $this->assertSame(2, $work->manuscripts_count);
        $this->assertSame($work->id, DB::table('manuscripts')->where('id', $wrong)->value('work_id'));
        $made = Manuscript::where('work_id', $work->id)->where('shelfmark', '88/2')->sole();
        $this->assertSame(['تهران', 'کتابخانه ملی'], [$made->city, $made->library]);
        $this->assertSame('[ف: 1-2]', $made->metadata['catalog_citation']);
        $row = DB::table('work_people')->where('work_id', $work->id)->sole();
        $this->assertSame(['translator', 'مترجم دیگر آزمایشی', 'probable'], [$row->role, $row->name, $row->certainty]);

        // entry A keeps only its own copy, and its author is its own
        $this->assertSame(['66/3'], Manuscript::where('work_id', $a)->pluck('shelfmark')->all());
        $this->assertSame('مؤلف درست', DB::table('works')->where('id', $a)->value('author_name'));
        $this->assertSame(1, (int) DB::table('works')->where('id', $a)->value('manuscripts_count'));
        $this->assertSame('corpus_update', AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $wrong)->sole()->action);

        $this->artisan('fahares:add-corpus-works', ['--file' => $this->file])
            ->expectsOutputToContain('Would add 0 works, fill 0 works and update 0; copies: 0 moved, 0 made.')
            ->assertSuccessful();
    }

    public function test_an_update_leaves_a_reviewed_column_that_already_holds_its_value_and_writes_the_rest()
    {
        // a head the OCR lost (step 14.19): a user's suggestion already gave the author and linked the person
        $id = $this->work('اثر آزمایشی ج', 'test-jim-v99-9002', 'مؤلف آزمایشی');
        DB::table('works')->where('id', $id)->update(['author_id' => null, 'raw_text' => "● اثر آزمایشی ج\nasar"]);
        AuditLog::create(['auditable_type' => Work::class, 'auditable_id' => $id, 'action' => 'verify',
            'old_values' => ['author_name' => null], 'new_values' => ['author_name' => 'مؤلف آزمایشی']]);
        $update = ['v2' => 'v99-2', 'work_slug' => 'test-jim-v99-9002', 'author' => ['name' => 'مؤلف آزمایشی', 'century' => 9],
            'changes' => [
                'author_name' => ['from' => null, 'to' => 'مؤلف آزمایشی'],
                'print_info' => ['from' => null, 'to' => 'لاهور، 1302ق'],
                'raw_text' => ['from' => "● اثر آزمایشی ج\nasar", 'to' => "● اثر آزمایشی ج\nasar\n\nمؤلف آزمایشی\nچاپ: لاهور، 1302ق"],
            ]];
        file_put_contents($this->file, json_encode(['category' => 'lost_head', 'works' => [], 'work_updates' => [$update]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:add-corpus-works', ['--file' => $this->file])
            ->expectsOutputToContain('Would add 0 works, fill 0 works and update 1; copies: 0 moved, 0 made.')
            ->assertSuccessful();
        $this->artisan('fahares:add-corpus-works', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $work = Work::find($id);
        $this->assertSame('لاهور، 1302ق', $work->print_info);
        $this->assertStringEndsWith('چاپ: لاهور، 1302ق', $work->raw_text);
        $log = AuditLog::where('auditable_id', $id)->where('action', 'corpus_update')->sole();
        $this->assertSame('lost_head', $log->new_values['category']);
        $this->assertArrayNotHasKey('author_name', $log->new_values);
        $this->assertArrayNotHasKey('author_id', $log->new_values);

        // a column the update would change is still guarded
        AuditLog::create(['auditable_type' => Work::class, 'auditable_id' => $id, 'action' => 'verify',
            'old_values' => ['description' => null], 'new_values' => ['description' => 'شرح بازبینی‌شده']]);
        $update['changes'] = ['description' => ['from' => null, 'to' => 'شرح v2']];
        file_put_contents($this->file, json_encode(['works' => [], 'work_updates' => [$update]], JSON_UNESCAPED_UNICODE));
        $this->artisan('fahares:add-corpus-works', ['--file' => $this->file])
            ->expectsOutputToContain('test-jim-v99-9002: protected by a reviewed correction')
            ->assertSuccessful();
    }

    public function test_a_shelfmark_the_work_holds_twice_moves_by_its_sequence()
    {
        // vol 11: «رضوی 7141» twice under Deylamaj's work, the second one Kermanshahi's (step 14.23)
        $a = $this->work('اثر آزمایشی د', 'test-dal-v99-9003', 'مؤلف دیگر');
        $first = $this->copy($a, "4. مشهد؛ رضوی؛ شماره نسخه: 7141", 'مشهد', 'رضوی', '7141');
        $second = $this->copy($a, "10. مشهد؛ رضوی؛ شماره نسخه: 7141", 'مشهد', 'رضوی', '7141');
        DB::table('manuscripts')->where('id', $first)->update(['sequence_number' => 4]);
        DB::table('manuscripts')->where('id', $second)->update(['sequence_number' => 10]);
        $columns = ['primary_title' => 'اثر آزمایشی ه', 'clean_title' => 'اثر آزمایشی ه', 'alternative_titles' => null,
            'transliteration' => 'asar', 'author_name' => 'مؤلف آزمایشی', 'composition_date_raw' => null,
            'composition_place' => null, 'work_form' => null, 'subject_summary' => 'فقه', 'language_summary' => 'عربی',
            'incipit_text' => null, 'explicit_text' => null, 'description' => null, 'bibliography' => null,
            'print_info' => null, 'dedication' => null, 'related_work' => null, 'commentaries_and_glosses' => null,
            'raw_text' => '● اثر آزمایشی ه / فقه / عربی', 'volume_number' => 99, 'page_start' => 10, 'page_end' => 10];
        $move = ['id' => 0, 'from_slug' => 'test-dal-v99-9003', 'city' => 'مشهد', 'library' => 'رضوی', 'shelfmark' => '7141'];
        file_put_contents($this->file, json_encode(['works' => [[
            'v2' => 'v99-3', 'slug_suffix' => '-v99-9001', 'columns' => $columns, 'author' => null, 'roles' => [],
            'subjects' => [], 'languages' => [],
            'copies' => [['move' => $move + ['from_sequence' => 10], 'sequence_number' => 1]],
        ]], 'work_updates' => []], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:add-corpus-works', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $work = Work::where('slug', 'like', '%-v99-9001')->sole();
        $this->assertSame($work->id, DB::table('manuscripts')->where('id', $second)->value('work_id'));
        $this->assertSame($a, DB::table('manuscripts')->where('id', $first)->value('work_id'));
    }
}
