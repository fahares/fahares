<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RebuildCopiesCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'rebuild');
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->manuscripts)->delete();
        DB::table('source_entries')->where('citable_type', Manuscript::class)->whereIn('citable_id', $this->manuscripts)->delete();
        DB::table('manuscript_scribe')->whereIn('manuscript_id', $this->manuscripts)->delete();
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function copy(string $shelfmark, string $raw, ?string $date, array $metadata): int
    {
        $work = $this->works[] = DB::table('works')->insertGetId([
            'primary_title' => 'اثر آزمایشی بازسازی', 'clean_title' => 'اثر آزمایشی بازسازی',
            'slug' => 'test-rebuild-' . uniqid(), 'volume_number' => 99, 'page_start' => 1,
        ]);

        return $this->manuscripts[] = DB::table('manuscripts')->insertGetId([
            'work_id' => $work, 'sequence_number' => 2, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1,
            'city' => 'تهران', 'library' => 'دانشگاه', 'shelfmark' => $shelfmark, 'raw_text' => $raw,
            'copy_date_raw' => $date, 'is_bita' => 0, 'scribe_name' => 'کاتب دیگر', 'script_names' => 'نستعلیق',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function record(int $id, string $shelfmark, array $changes): array
    {
        return ['table' => 'manuscripts', 'id' => $id, 'shelfmark' => $shelfmark, 'category' => 'rebuild', 'v2' => 'v99-1#1', 'changes' => $changes];
    }

    public function test_rebuilds_a_copy_still_holding_the_neighbours_content_and_leaves_an_edited_one()
    {
        $oldMeta = ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [], 'catalog_citation' => '[عکسی - ف: 3-665]', 'residual_notes' => null];
        $newMeta = ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [], 'catalog_citation' => '[فیلم‌ها - ف: 3-193]', 'residual_notes' => null];
        $wrong = $this->copy('6030/8-ف', "قم؛ مرعشی؛ شماره نسخه: 1296/21\nخط: نستعلیق؛ بی‌کا، بی‌تا", '1100ق', $oldMeta);
        $edited = $this->copy('6031/8-ف', 'متن ویرایش‌شده', '1100ق', $oldMeta);
        DB::table('manuscript_scribe')->insert(['manuscript_id' => $wrong, 'name' => 'کاتب دیگر', 'position' => 0]);
        $changes = fn ($raw) => [
            'raw_text' => ['from' => $raw, 'to' => "تهران؛ دانشگاه؛ شماره نسخه: 6030/8-ف\nنسخه اصل: همان نسخه بالا [فیلم‌ها - ف: 3-193]"],
            'copy_date_raw' => ['from' => '1100ق', 'to' => null],
            'scribe_name' => ['from' => 'کاتب دیگر', 'to' => null],
            'metadata' => ['from' => $oldMeta, 'to' => $newMeta],
        ];
        file_put_contents($this->file, json_encode(['records' => [
            $this->record($wrong, '6030/8-ف', $changes("قم؛ مرعشی؛ شماره نسخه: 1296/21\nخط: نستعلیق؛ بی‌کا، بی‌تا")),
            $this->record($edited, '6031/8-ف', $changes('متن پیش از ویرایش')),
        ]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file])
            ->expectsOutputToContain('Would rebuild 1 copies.')
            ->assertSuccessful();
        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $row = DB::table('manuscripts')->where('id', $wrong)->first();
        $this->assertStringStartsWith('تهران؛ دانشگاه؛ شماره نسخه: 6030/8-ف', $row->raw_text);
        $this->assertNull($row->copy_date_raw);
        $this->assertNull($row->scribe_name);
        $this->assertSame('[فیلم‌ها - ف: 3-193]', json_decode($row->metadata, true)['catalog_citation']);
        $this->assertFalse(DB::table('manuscript_scribe')->where('manuscript_id', $wrong)->exists());
        // the copy's own text has no «خط:» line, so the other copy's script goes too
        $this->assertNull($row->script_names);
        $this->assertSame('corpus_update', AuditLog::where('auditable_id', $wrong)->where('auditable_type', Manuscript::class)->sole()->action);
        $this->assertSame('متن ویرایش‌شده', DB::table('manuscripts')->where('id', $edited)->value('raw_text'));

        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file])
            ->expectsOutputToContain('Would rebuild 0 copies.')
            ->assertSuccessful();
    }
}
