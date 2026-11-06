<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplyCorpusStructureCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'struct');
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->manuscripts)->delete();
        \App\Models\EntityRedirect::where('entity_type', 'manuscripts')->whereIn('source_id', $this->manuscripts)->delete();
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function work(string $key): int
    {
        return $this->works[] = DB::table('works')->insertGetId([
            'primary_title' => "اثر آزمایشی ساختار {$key}", 'clean_title' => "اثر آزمایشی ساختار {$key}",
            'slug' => 'test-structure-' . uniqid() . $key, 'volume_number' => 99, 'page_start' => 1,
        ]);
    }

    private function copy(int $work, int $seq, string $shelfmark, string $library = 'مجلس'): int
    {
        return $this->manuscripts[] = DB::table('manuscripts')->insertGetId([
            'work_id' => $work, 'sequence_number' => $seq, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1,
            'city' => 'تهران', 'library' => $library, 'shelfmark' => $shelfmark, 'raw_text' => "{$seq}. تهران؛ {$library}؛ شماره نسخه: {$shelfmark}",
        ]);
    }

    public function test_copies_move_to_the_right_work_and_duplicates_fold_into_the_first_row()
    {
        $wrong = $this->work('-v99-1');
        $right = $this->work('-v99-2');
        $dupWork = $this->work('-v99-3');
        $misplaced = $this->copy($wrong, 1, '657/14', 'ملک');
        $stays = $this->copy($wrong, 2, '13926/6');
        $first = $this->copy($dupWork, 83, '9466/45');
        $second = $this->copy($dupWork, 83, '9466/45');
        file_put_contents($this->file, json_encode([
            'moves' => [['from' => '-v99-1', 'to' => '-v99-2', 'copies' => [['تهران', 'ملک', '657/14'], ['تهران', 'ملک', '0/0']]]],
            'duplicates' => [['work' => '-v99-3', 'copies' => [[83, '9466/45']]]],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:apply-corpus-structure', ['--file' => $this->file])
            ->expectsOutputToContain('Would apply 1 moves and 1 folds.')
            ->expectsOutputToContain('تهران، ملک، 0/0: 0 copies')
            ->assertSuccessful();
        $this->assertSame($wrong, DB::table('manuscripts')->where('id', $misplaced)->value('work_id'));

        $this->artisan('fahares:apply-corpus-structure', ['--file' => $this->file, '--apply' => true])->assertSuccessful();
        $this->assertSame($right, DB::table('manuscripts')->where('id', $misplaced)->value('work_id'));
        $this->assertSame($wrong, DB::table('manuscripts')->where('id', $stays)->value('work_id'));
        $this->assertTrue(DB::table('manuscripts')->where('id', $first)->exists());
        $this->assertFalse(DB::table('manuscripts')->where('id', $second)->exists());
        $this->assertTrue(AuditLog::where('auditable_id', $misplaced)->where('action', 'verify')->exists());
        $this->assertTrue(AuditLog::where('auditable_id', $first)->where('action', 'merge')->exists());

        // a second run finds nothing left to do
        $this->artisan('fahares:apply-corpus-structure', ['--file' => $this->file])
            ->expectsOutputToContain('Would apply 0 moves and 0 folds.')
            ->assertSuccessful();
    }

    public function test_a_hybrid_copy_folds_into_its_twin_under_the_other_work_keeping_the_twins_values()
    {
        $wrong = $this->work('-v99-11');
        $right = $this->work('-v99-12');
        $hybrid = $this->copy($wrong, 3, 'بدون شماره', 'علامه فیض');
        DB::table('manuscripts')->where('id', $hybrid)->update(['is_illuminated' => 1, 'folios' => 9]);
        $twin = $this->copy($right, 69, 'بدون شماره', 'مدرسه علوی');
        $own = $this->copy($wrong, 1, '13602/4');
        file_put_contents($this->file, json_encode([
            'twins' => [['from' => '-v99-11', 'to' => '-v99-12', 'copies' => [[3, 69, 'بدون شماره'], [5, 70, '0/0']]]],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:apply-corpus-structure', ['--file' => $this->file])
            ->expectsOutputToContain('Would apply 0 moves and 0 folds. and 1 twin folds.')
            ->expectsOutputToContain('5. 0/0: 0 copies')
            ->assertSuccessful();
        $this->assertTrue(DB::table('manuscripts')->where('id', $hybrid)->exists());

        $this->artisan('fahares:apply-corpus-structure', ['--file' => $this->file, '--apply' => true])->assertSuccessful();
        $this->assertFalse(DB::table('manuscripts')->where('id', $hybrid)->exists());
        $kept = DB::table('manuscripts')->where('id', $twin)->first();
        $this->assertSame('مدرسه علوی', $kept->library);
        $this->assertSame(0, (int) $kept->is_illuminated);
        $this->assertNull($kept->folios);
        $this->assertSame($right, $kept->work_id);
        $this->assertTrue(DB::table('manuscripts')->where('id', $own)->exists());
        $this->assertTrue(AuditLog::where('auditable_id', $twin)->where('action', 'merge')->where('new_values', 'like', '%twin%')->exists());

        $this->artisan('fahares:apply-corpus-structure', ['--file' => $this->file])
            ->expectsOutputToContain('already folded: 3. بدون شماره')
            ->assertSuccessful();
    }
}
