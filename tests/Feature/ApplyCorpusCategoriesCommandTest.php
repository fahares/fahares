<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplyCorpusCategoriesCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'categories');
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->manuscripts)->delete();
        AuditLog::where('auditable_type', Work::class)->whereIn('auditable_id', $this->works)->delete();
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function work(string $key, ?string $print = null, ?array $bib = null): int
    {
        return $this->works[] = DB::table('works')->insertGetId([
            'primary_title' => "اثر آزمایشی دسته {$key}", 'clean_title' => "اثر آزمایشی دسته {$key}",
            'slug' => 'test-categories-' . uniqid() . $key, 'volume_number' => 99, 'page_start' => 1,
            'print_info' => $print, 'bibliography' => $bib ? json_encode($bib, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function copy(int $work, string $shelfmark, int $facsimile = 0): int
    {
        return $this->manuscripts[] = DB::table('manuscripts')->insertGetId([
            'work_id' => $work, 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1,
            'city' => 'تهران', 'library' => 'دانشگاه', 'shelfmark' => $shelfmark, 'is_facsimile' => $facsimile,
        ]);
    }

    private function plan(array $records): void
    {
        file_put_contents($this->file, json_encode(['records' => $records], JSON_UNESCAPED_UNICODE));
    }

    private function facsimile(int $id, string $key, string $shelfmark): array
    {
        return ['table' => 'manuscripts', 'id' => $id, 'work_key' => $key, 'shelfmark' => $shelfmark,
            'category' => 'facsimile', 'changes' => ['is_facsimile' => ['from' => 0, 'to' => 1]]];
    }

    private function printBib(int $id, string $key, string $from, string $to, array $cites): array
    {
        return ['table' => 'works', 'id' => $id, 'work_key' => $key, 'category' => 'print_bib',
            'changes' => ['print_info' => ['from' => $from, 'to' => $to]], 'require_in_bibliography' => $cites];
    }

    public function test_writes_only_unchanged_unprotected_records_whose_conditions_hold()
    {
        $w1 = $this->work('-v99-1', 'تهران، 1297ق [منزوی 3259/5]', ['منزوی 3259/5']);
        $w2 = $this->work('-v99-2', 'قم، 1300ق [الذریعة 1/2]', ['دنا 3/4']);
        $w3 = $this->work('-v99-3');
        $fresh = $this->copy($w3, '12/1-ف');
        $edited = $this->copy($w3, '12/2-ف');
        $moved = $this->copy($w3, '12/3-ف');
        $done = $this->copy($w3, '12/4-ف', 1);
        $guarded = $this->copy($w3, '12/5-ف');
        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $guarded,
            'action' => 'verify', 'old_values' => ['is_facsimile' => 1], 'new_values' => ['is_facsimile' => 0]]);
        $this->plan([
            $this->facsimile($fresh, '-v99-3', '12/1-ف'),
            ['changes' => ['is_facsimile' => ['from' => 1, 'to' => 1]]] + $this->facsimile($edited, '-v99-3', '12/2-ف'),
            $this->facsimile($moved, '-v99-8', '12/3-ف'),
            $this->facsimile($done, '-v99-3', '12/4-ف'),
            $this->facsimile($guarded, '-v99-3', '12/5-ف'),
            $this->printBib($w1, '-v99-1', 'تهران، 1297ق [منزوی 3259/5]', 'تهران، 1297ق', ['منزوی 3259/5']),
            $this->printBib($w2, '-v99-2', 'قم، 1300ق [الذریعة 1/2]', 'قم، 1300ق', ['الذریعة 1/2']),
        ]);

        $this->artisan('fahares:apply-corpus-categories', ['--file' => $this->file])
            ->expectsOutputToContain('Would write 2 records.')
            ->assertSuccessful();
        $this->assertSame(0, (int) DB::table('manuscripts')->where('id', $fresh)->value('is_facsimile'));

        $this->artisan('fahares:apply-corpus-categories', ['--file' => $this->file, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, (int) DB::table('manuscripts')->where('id', $fresh)->value('is_facsimile'));
        $this->assertSame(0, (int) DB::table('manuscripts')->where('id', $moved)->value('is_facsimile'));
        $this->assertSame(0, (int) DB::table('manuscripts')->where('id', $guarded)->value('is_facsimile'));
        $this->assertSame('تهران، 1297ق', DB::table('works')->where('id', $w1)->value('print_info'));
        // its citation is not in the bibliography, so «چاپ» keeps the bracket
        $this->assertSame('قم، 1300ق [الذریعة 1/2]', DB::table('works')->where('id', $w2)->value('print_info'));
        $log = AuditLog::where('auditable_id', $w1)->where('auditable_type', Work::class)->sole();
        $this->assertSame('corpus_update', $log->action);
        $this->assertSame('تهران، 1297ق [منزوی 3259/5]', $log->old_values['print_info']);

        // a second run has nothing left to write
        $this->artisan('fahares:apply-corpus-categories', ['--file' => $this->file])
            ->expectsOutputToContain('Would write 0 records.')
            ->assertSuccessful();
    }
}
