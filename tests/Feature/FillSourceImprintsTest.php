<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Support\SourceReference;
use Tests\TestCase;

/** The printed-book record of sources and volumes, filled from a decision file (step 14.394, backlog item 171). */
class FillSourceImprintsTest extends TestCase
{
    private function file(array $sources): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imprints');
        file_put_contents($path, json_encode(['sources' => $sources], JSON_UNESCAPED_UNICODE));

        return $path;
    }

    public function test_the_record_is_written_only_with_apply_and_only_once()
    {
        $source = Source::create(['code' => 'test-golpaygani', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 50,
            'title' => 'فهرست نسخه‌های خطی کتابخانۀ گلپایگانی', 'publisher' => 'مؤسسه الجواد', 'unit' => 'صفحه']);
        $v1 = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1, 'facsimile_code' => 'golpaygani',
            'publisher' => 'مؤسسه الجواد', 'publication_year' => Source::UNPUBLISHED]);
        $file = $this->file(['test-golpaygani' => [
            'set' => ['compiler' => 'مصطفی درایتی', 'edition_label' => 'اول', 'publication_place' => 'تهران',
                'publisher' => 'کتابخانه، موزه و مرکز اسناد مجلس شورای اسلامی', 'publication_year' => '۱۳۸۸'],
            'volumes' => [['volume' => 1, 'set' => ['publisher' => null, 'publication_year' => null]]],
        ]]);

        $this->assertTrue((new SourceReference($source, $v1))->unpublished());

        $this->artisan('fahares:fill-source-imprints', ['--file' => $file])
            ->expectsOutputToContain('test-golpaygani  publication_place: ∅ → تهران')
            ->expectsOutputToContain('test-golpaygani ج 1  publication_year: مخطوط → ∅')
            ->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertNull($source->fresh()->publication_place);
        $this->assertSame(Source::UNPUBLISHED, $v1->fresh()->publication_year);

        $this->artisan('fahares:fill-source-imprints', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $source->refresh();
        $v1->refresh();
        $this->assertSame('مصطفی درایتی', $source->compiler);
        $this->assertSame('۱۳۸۸', $source->publication_year);
        $this->assertNull($v1->publication_year);
        $this->assertNull($v1->publisher);
        $this->assertSame([], (new SourceReference($source->load('volumes'), $v1))->missing());
        $this->assertSame(1, AuditLog::where('auditable_type', Source::class)->where('auditable_id', $source->id)->count());
        $this->assertSame(1, AuditLog::where('auditable_type', SourceVolume::class)->where('auditable_id', $v1->id)->count());

        // a second run finds nothing to change
        $this->artisan('fahares:fill-source-imprints', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('unchanged            2')->assertSuccessful();
        $this->assertSame(1, AuditLog::where('auditable_type', Source::class)->where('auditable_id', $source->id)->count());
    }

    public function test_a_shared_volume_number_is_told_apart_by_its_facsimile_code_and_an_unknown_one_is_skipped()
    {
        $source = Source::create(['code' => 'test-majlis', 'kind' => 'library_catalogue', 'rank' => 50, 'title' => 'فهرست کتابخانۀ مجلس', 'unit' => 'صفحه']);
        $p1 = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 24, 'facsimile_code' => 'majlis-p1']);
        $p2 = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 24, 'facsimile_code' => 'majlis-p2']);
        $file = $this->file([
            'test-majlis' => ['volumes' => [
                ['volume' => 24, 'facsimile_code' => 'majlis-p2', 'set' => ['publication_year' => '۱۳۸۹']],
                ['volume' => 24, 'set' => ['publication_year' => '۱۳۹۰']],
                ['volume' => 99, 'set' => ['publication_year' => '۱۳۹۰']],
                ['volume' => 24, 'facsimile_code' => 'majlis-p1', 'set' => ['compiler' => 'کسی']],
            ]],
            'no-such-source' => ['set' => ['publisher' => 'x']],
        ]);

        $this->artisan('fahares:fill-source-imprints', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('test-majlis ج 24: 2 volumes, skipped')
            ->expectsOutputToContain('test-majlis ج 99: no volume, skipped')
            ->expectsOutputToContain('unknown field compiler')
            ->expectsOutputToContain('No source «no-such-source»')
            ->assertSuccessful();
        $this->assertSame('۱۳۸۹', $p2->fresh()->publication_year);
        $this->assertNull($p1->fresh()->publication_year);
    }

    public function test_the_shipped_decision_file_is_well_formed()
    {
        $data = json_decode(file_get_contents(base_path('database/data/source_imprints_step14_394.json')), true);
        $this->assertIsArray($data['sources'] ?? null);
        foreach ($data['sources'] as $code => $decision) {
            $this->assertSame([], array_diff(array_keys($decision['set'] ?? []), \App\Console\Commands\FillSourceImprintsCommand::SOURCE_FIELDS), $code);
            foreach ($decision['volumes'] ?? [] as $row) {
                $this->assertIsInt($row['volume'], $code);
                $this->assertSame([], array_diff(array_keys($row['set'] ?? []), \App\Console\Commands\FillSourceImprintsCommand::VOLUME_FIELDS), $code);
            }
        }
    }
}
