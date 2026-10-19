<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplyCorpusDiffCommandTest extends TestCase
{
    private array $ids = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'diff');
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Work::class)->whereIn('auditable_id', $this->ids)->delete();
        DB::table('works')->whereIn('id', $this->ids)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function work(int $number, array $attributes): int
    {
        return $this->ids[] = DB::table('works')->insertGetId($attributes + [
            'primary_title' => "اثر آزمایشی مآخذ {$number}",
            'clean_title' => 'اثر آزمایشی مآخذ',
            'slug' => 'test-corpus-diff-' . uniqid() . "-v99-{$number}",
            'volume_number' => 99,
            'page_start' => 1,
        ]);
    }

    private function diff(array $works): void
    {
        file_put_contents($this->file, json_encode(['from' => 'v1', 'to' => 'v2', 'works' => $works], JSON_UNESCAPED_UNICODE));
    }

    private function bibChange(int $number, array $old, array $new): array
    {
        return ['volume' => 99, 'number' => $number, 'title' => "اثر {$number}", 'fields' => ['bibliography' => ['old' => $old, 'new' => $new]]];
    }

    public function test_only_values_the_site_has_not_edited_are_replaced_and_edited_text_is_patched()
    {
        $old = ['الذریعة 865/9', '221/2'];
        $new = ['الذریعة 865/9؛ 221/2'];
        $untouched = $this->work(1, ['bibliography' => json_encode($old, JSON_UNESCAPED_UNICODE)]);
        $edited = $this->work(2, ['bibliography' => json_encode(['مأخذ ویرایش‌شده'], JSON_UNESCAPED_UNICODE)]);
        $protected = $this->work(3, ['bibliography' => json_encode($old, JSON_UNESCAPED_UNICODE)]);
        AuditLog::create(['auditable_type' => Work::class, 'auditable_id' => $protected, 'action' => 'verify', 'new_values' => ['bibliography' => ['x']]]);
        $text = $this->work(4, ['description' => 'عنوان ویرایش‌شده. در الذریعه   معرفی شده است.']);

        $this->diff([
            $this->bibChange(1, $old, $new),
            $this->bibChange(2, $old, $new),
            $this->bibChange(3, $old, $new),
            ['volume' => 99, 'number' => 4, 'title' => 'اثر 4', 'fields' => ['description' => [
                'old' => 'عنوان. در الذریعه   معرفی شده است.',
                'new' => 'عنوان. در الذریعه [17/16] معرفی شده است.',
                'patches' => [['old' => 'در الذریعه   معرفی', 'new' => 'در الذریعه [17/16] معرفی']],
            ]]],
            $this->bibChange(5, $old, $new), // no such work
        ]);

        $this->artisan('fahares:apply-corpus-diff', ['--file' => $this->file])->assertSuccessful();
        $this->assertSame(json_encode($old, JSON_UNESCAPED_UNICODE), DB::table('works')->where('id', $untouched)->value('bibliography'));

        $this->artisan('fahares:apply-corpus-diff', ['--file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('Updated 2 works.')
            ->expectsOutputToContain('bibliography was edited on the site')
            ->expectsOutputToContain('bibliography has a reviewed correction')
            ->expectsOutputToContain('0 matching works')
            ->assertSuccessful();

        $this->assertSame($new, json_decode(DB::table('works')->where('id', $untouched)->value('bibliography'), true));
        $this->assertSame(['مأخذ ویرایش‌شده'], json_decode(DB::table('works')->where('id', $edited)->value('bibliography'), true));
        $this->assertSame($old, json_decode(DB::table('works')->where('id', $protected)->value('bibliography'), true));
        $this->assertSame('عنوان ویرایش‌شده. در الذریعه [17/16] معرفی شده است.', DB::table('works')->where('id', $text)->value('description'));

        // A second run finds everything in place
        $this->artisan('fahares:apply-corpus-diff', ['--file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('Updated 0 works.')
            ->assertSuccessful();
    }
}
