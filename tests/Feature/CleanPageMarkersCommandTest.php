<?php

namespace Tests\Feature;

use App\Console\Commands\CleanPageMarkersCommand;
use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanPageMarkersCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->manuscripts)->delete();
        DB::table('source_entries')->whereIn('legacy_type', ['work', 'work_bibliography'])->whereIn('legacy_id', $this->works)->delete();
        DB::table('source_entries')->where('legacy_type', 'manuscript')->whereIn('legacy_id', $this->manuscripts)->delete();
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        parent::tearDown();
    }

    private function work(array $attributes): int
    {
        return $this->works[] = DB::table('works')->insertGetId($attributes + [
            'primary_title' => 'اثر آزمایشی برچسب صفحه',
            'clean_title' => 'اثر آزمایشی برچسب صفحه',
            'slug' => 'test-page-markers-' . uniqid(),
            'volume_number' => 99,
            'page_start' => 1,
        ]);
    }

    private function manuscript(int $work, array $attributes): int
    {
        return $this->manuscripts[] = DB::table('manuscripts')->insertGetId($attributes + [
            'work_id' => $work, 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1,
        ]);
    }

    public function test_strip_text_keeps_line_breaks_and_drops_a_value_that_was_only_a_marker()
    {
        $this->assertSame('الطبیب الطهرانی', CleanPageMarkersCommand::stripText('الطبیب <!-- page: 60 --> الطهرانی'));
        $this->assertSame("سطر یک\nسطر دو", CleanPageMarkersCommand::stripText("سطر یک\n<!-- page: 61 -->\nسطر دو"));
        $this->assertSame('[ف: 489-4]', CleanPageMarkersCommand::stripText('[ف: <!-- page: 169 -->489-4]'));
        $this->assertNull(CleanPageMarkersCommand::stripText(' <!-- page: 7 --> '));
        $this->assertSame('بی‌برچسب', CleanPageMarkersCommand::stripText('بی‌برچسب'));
        $this->assertSame(['الذریعة 6/1', 'دنا 5/3'], CleanPageMarkersCommand::stripJson(['الذریعة <!-- page: 2 --> 6/1', '<!-- page: 3 -->', 'دنا 5/3']));
    }

    public function test_fields_are_cleaned_raw_text_is_kept_and_reviewed_columns_are_left_alone()
    {
        $work = $this->work([
            'description' => "معرفی <!-- page: 10 --> اثر\nسطر دوم",
            'bibliography' => json_encode(['الذریعة <!-- page: 10 --> 6/1'], JSON_UNESCAPED_UNICODE),
            'raw_text' => 'متن <!-- page: 10 --> فنخا',
        ]);
        $ms = $this->manuscript($work, [
            'explicit_text' => 'انجام <!-- page: 11 --> نسخه',
            'binding' => 'تیماج <!-- page: 11 -->',
            'metadata' => json_encode(['explicits' => [['label' => null, 'text' => 'انجام <!-- page: 11 --> نسخه']], 'catalog_citation' => '[ف: <!-- page: 11 --> 2-3]'], JSON_UNESCAPED_UNICODE),
        ]);
        $reviewed = $this->manuscript($work, ['explicit_text' => 'اصلاح‌شده <!-- page: 12 --> داور', 'paper' => 'کاغذ <!-- page: 12 --> فرنگی']);
        AuditLog::create(['auditable_type' => Manuscript::class, 'auditable_id' => $reviewed, 'action' => 'verify', 'new_values' => ['explicit_text' => 'x']]);

        $this->artisan('fahares:clean-page-markers', ['--fields' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertSame('انجام <!-- page: 11 --> نسخه', DB::table('manuscripts')->where('id', $ms)->value('explicit_text'));

        $this->artisan('fahares:clean-page-markers', ['--fields' => true])
            ->expectsOutputToContain("manuscripts #{$reviewed}: explicit_text has a reviewed correction, left as is")
            ->assertSuccessful();

        $w = DB::table('works')->where('id', $work)->first();
        $this->assertSame("معرفی اثر\nسطر دوم", $w->description);
        $this->assertSame(['الذریعة 6/1'], json_decode($w->bibliography, true));
        $this->assertSame('متن <!-- page: 10 --> فنخا', $w->raw_text);

        $m = DB::table('manuscripts')->where('id', $ms)->first();
        $this->assertSame('انجام نسخه', $m->explicit_text);
        $this->assertSame('تیماج', $m->binding);
        $meta = json_decode($m->metadata, true);
        $this->assertSame('انجام نسخه', $meta['explicits'][0]['text']);
        $this->assertSame('[ف: 2-3]', $meta['catalog_citation']);

        $r = DB::table('manuscripts')->where('id', $reviewed)->first();
        $this->assertSame('اصلاح‌شده <!-- page: 12 --> داور', $r->explicit_text);
        $this->assertSame('کاغذ فرنگی', $r->paper);
    }
}
