<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cataloger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CorrectCatalogerProfilesTest extends TestCase
{
    use DatabaseTransactions;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = 'storage/framework/testing-cataloger-corrections-' . uniqid() . '.json';
        file_put_contents(base_path($this->file), json_encode([[
            'slug' => 'test-cataloger-correction',
            'name' => 'فهرست‌نگار آزمایشی',
            'fields' => [
                'birth_year_solar' => ['from' => 1318, 'to' => 1344],
                'death_year_solar' => ['from' => 1390, 'to' => null],
                'title_prefix' => ['from' => 'استاد دکتر', 'to' => null],
                'bio' => ['from' => 'زندگی‌نامه‌ی ساختگی', 'to' => 'زندگی‌نامه‌ی منبع‌دار'],
                'metadata.birth_place' => ['from' => 'شیراز', 'to' => 'کربلا'],
                'metadata.specialty' => ['from' => 'تخصص ساختگی', 'to' => null],
            ],
            'sources' => [['title' => 'ویکی‌نور', 'url' => 'https://example.org/wikinoor']],
        ]], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->file));
        parent::tearDown();
    }

    private function generated(array $overrides = []): Cataloger
    {
        return Cataloger::create(array_merge([
            'name' => 'فهرست‌نگار آزمایشی',
            'slug' => 'test-cataloger-correction',
            'birth_year_solar' => 1318,
            'death_year_solar' => 1390,
            'title_prefix' => 'استاد دکتر',
            'bio' => 'زندگی‌نامه‌ی ساختگی',
            'metadata' => ['birth_place' => 'شیراز', 'specialty' => 'تخصص ساختگی'],
        ], $overrides));
    }

    public function test_generated_fields_are_replaced_and_sources_recorded()
    {
        $cataloger = $this->generated();

        $this->artisan('catalogers:correct-profiles', ['--file' => $this->file])->assertSuccessful();

        $cataloger->refresh();
        $this->assertSame(1344, $cataloger->birth_year_solar);
        $this->assertNull($cataloger->death_year_solar);
        $this->assertNull($cataloger->title_prefix);
        $this->assertSame('زندگی‌نامه‌ی منبع‌دار', $cataloger->bio);
        $this->assertSame('کربلا', $cataloger->metadata['birth_place']);
        $this->assertArrayNotHasKey('specialty', $cataloger->metadata);
        $this->assertSame('ویکی‌نور', $cataloger->metadata['sources'][0]['title']);
        $this->assertTrue(AuditLog::where('auditable_id', $cataloger->id)->where('action', 'profile_correction')->exists());
    }

    public function test_fields_edited_by_hand_are_kept()
    {
        $cataloger = $this->generated([
            'birth_year_solar' => 1345,
            'bio' => 'زندگی‌نامه‌ای که ویراستار نوشته است',
        ]);

        $this->artisan('catalogers:correct-profiles', ['--file' => $this->file])->assertSuccessful();

        $cataloger->refresh();
        $this->assertSame(1345, $cataloger->birth_year_solar);
        $this->assertSame('زندگی‌نامه‌ای که ویراستار نوشته است', $cataloger->bio);
        $this->assertNull($cataloger->death_year_solar);
        $this->assertNull($cataloger->title_prefix);
    }

    public function test_dry_run_and_second_run_change_nothing()
    {
        $cataloger = $this->generated();

        $this->artisan('catalogers:correct-profiles', ['--file' => $this->file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(1318, $cataloger->refresh()->birth_year_solar);

        $this->artisan('catalogers:correct-profiles', ['--file' => $this->file])->assertSuccessful();
        $updatedAt = $cataloger->refresh()->updated_at;
        $this->artisan('catalogers:correct-profiles', ['--file' => $this->file])
            ->expectsOutputToContain('Catalogers corrected: 0')
            ->assertSuccessful();
        $this->assertEquals($updatedAt, $cataloger->refresh()->updated_at);
    }
}
