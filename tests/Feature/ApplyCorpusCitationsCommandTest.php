<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplyCorpusCitationsCommandTest extends TestCase
{
    private array $works = [];
    private array $copies = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'cite');
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->copies)->delete();
        DB::table('manuscripts')->whereIn('id', $this->copies)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function work(int $number): int
    {
        return $this->works[] = DB::table('works')->insertGetId([
            'primary_title' => "اثر آزمایشی ارجاع {$number}",
            'clean_title' => 'اثر آزمایشی ارجاع',
            'slug' => 'test-corpus-citations-' . uniqid() . "-v98-{$number}",
            'volume_number' => 98,
            'page_start' => 1,
        ]);
    }

    private function copy(int $workId, int $sequence, string $shelfmark, ?string $citation, array $notes): int
    {
        return $this->copies[] = DB::table('manuscripts')->insertGetId([
            'work_id' => $workId,
            'sequence_number' => $sequence,
            'volume_number' => 98,
            'page_start' => 1,
            'page_end' => 1,
            'shelfmark' => $shelfmark,
            'metadata' => json_encode(['editorial_notes' => $notes, 'catalog_citation' => $citation, 'residual_notes' => null], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function meta(int $id): array
    {
        return json_decode(DB::table('manuscripts')->where('id', $id)->value('metadata'), true);
    }

    public function test_citations_move_from_the_notes_only_where_the_site_still_has_the_old_release()
    {
        $work = $this->work(1);
        $plain = $this->copy($work, 1, '18074', null, ['رایانه']);
        $among = $this->copy($work, 2, '4091/4', null, ['از طهارت تا حج', 'ف مخ: 1', 'تذکر: نام مؤلف به قرینه آغاز نسخه تعیین شد']);
        $cited = $this->copy($work, 3, '500', '[ف: 2 - 45]', ['الفبائی: -512']);
        $protected = $this->copy($work, 4, '600', null, ['رایانه']);
        AuditLog::create(['auditable_type' => Manuscript::class, 'auditable_id' => $protected, 'action' => 'verify', 'new_values' => ['metadata' => ['x']]]);
        // its work was merged away: found by volume and shelfmark
        $moved = $this->copy($this->work(7), 9, '700', null, ['د.ث. مجلس']);
        // Fankha lists this copy twice
        $twice = [$this->copy($work, 8, '800', null, ['رایانه']), $this->copy($work, 8, '800', null, ['رایانه'])];

        file_put_contents($this->file, json_encode(['from' => 'v1', 'to' => 'v2', 'copies' => [
            [98, 1, 1, '18074', '[رایانه]', 0],
            [98, 1, 2, '4091/4', '[ف مخ: 1]', 1],
            [98, 1, 3, '500', '[الفبائی: -512]', 0],
            [98, 1, 4, '600', '[رایانه]', 0],
            [98, 5, 2, '700', '[د.ث. مجلس]', 0],
            [98, 6, 1, '999', '[رایانه]', 0],
            [98, 1, 8, '800', '[رایانه]', 0],
            [98, 1, 8, '800', '[رایانه]', 0],
        ]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:apply-corpus-citations', ['--file' => $this->file])->expectsOutputToContain('Would update 5 copies.')->assertSuccessful();
        $this->assertNull($this->meta($plain)['catalog_citation']);

        $this->artisan('fahares:apply-corpus-citations', ['--file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('Updated 5 copies.')
            ->expectsOutputToContain('the site has another citation or edited notes')
            ->expectsOutputToContain('metadata has a reviewed correction')
            ->expectsOutputToContain('no single copy found')
            ->assertSuccessful();

        $this->assertSame(['editorial_notes' => [], 'catalog_citation' => '[رایانه]', 'residual_notes' => null], $this->meta($plain));
        $this->assertSame('[ف مخ: 1]', $this->meta($among)['catalog_citation']);
        $this->assertSame(['از طهارت تا حج', 'تذکر: نام مؤلف به قرینه آغاز نسخه تعیین شد'], $this->meta($among)['editorial_notes']);
        $this->assertSame('[ف: 2 - 45]', $this->meta($cited)['catalog_citation']);
        $this->assertSame(['الفبائی: -512'], $this->meta($cited)['editorial_notes']);
        $this->assertNull($this->meta($protected)['catalog_citation']);
        $this->assertSame('[د.ث. مجلس]', $this->meta($moved)['catalog_citation']);
        foreach ($twice as $id) {
            $this->assertSame('[رایانه]', $this->meta($id)['catalog_citation']);
        }

        $this->artisan('fahares:apply-corpus-citations', ['--file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('Updated 0 copies.')->assertSuccessful();
    }
}
