<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MoveEntryStatusCommandTest extends TestCase
{
    private array $works = [];

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Work::class)->whereIn('auditable_id', $this->works)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        parent::tearDown();
    }

    private function work(?string $description): int
    {
        return $this->works[] = DB::table('works')->insertGetId([
            'primary_title' => 'اثر آزمایشی وضع', 'clean_title' => 'اثر آزمایشی وضع', 'slug' => 'test-status-' . uniqid(),
            'volume_number' => 99, 'page_start' => 1, 'description' => $description,
        ]);
    }

    public function test_a_description_that_is_only_the_label_becomes_the_entry_status()
    {
        config(['scout.driver' => 'collection']);
        $hetero = $this->work('غیر همانند:');
        $probably = $this->work('ظاهرا غیر همانند:');
        $unknown = $this->work("ناشناخته:\n");
        $real = $this->work('غیر همانند: دو رساله با یک عنوان');

        $this->artisan('fahares:move-entry-status')->expectsOutputToContain('Would write 3 works.')->assertSuccessful();
        $this->artisan('fahares:move-entry-status', ['--apply' => true])->assertSuccessful();

        $rows = DB::table('works')->whereIn('id', $this->works)->get()->keyBy('id');
        $this->assertSame([null, 'heterogeneous'], [$rows[$hetero]->description, $rows[$hetero]->entry_status]);
        $this->assertSame('probably_heterogeneous', $rows[$probably]->entry_status);
        $this->assertSame('unidentified', $rows[$unknown]->entry_status);
        // a description with more than the label stays as it is
        $this->assertSame(['غیر همانند: دو رساله با یک عنوان', null], [$rows[$real]->description, $rows[$real]->entry_status]);
        $this->assertSame('غیر همانند:', AuditLog::where('auditable_type', Work::class)->where('auditable_id', $hetero)->sole()->old_values['description']);
        $this->artisan('fahares:move-entry-status')->expectsOutputToContain('Would write 0 works.')->assertSuccessful();
        $this->followingRedirects()->get("/works/{$hetero}")->assertOk()->assertSee('غیر همانند');
    }
}
