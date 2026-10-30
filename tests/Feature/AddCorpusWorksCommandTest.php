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
}
