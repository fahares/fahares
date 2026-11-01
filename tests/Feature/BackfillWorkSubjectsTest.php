<?php

namespace Tests\Feature;

use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A work whose title names its subject gets a work_people row with the subject role (backlog item 35). */
class BackfillWorkSubjectsTest extends TestCase
{
    private array $people = [];

    private array $works = [];

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'subjects');
    }

    protected function tearDown(): void
    {
        DB::table('work_people')->whereIn('work_id', $this->works)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        DB::table('people')->whereIn('id', $this->people)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function person(string $name): int
    {
        DB::table('people')->where('name', $name)->delete();

        return $this->people[] = DB::table('people')->insertGetId(['name' => $name, 'normalized_name' => $name, 'slug' => 'test-subject-' . uniqid()]);
    }

    private function work(string $title): int
    {
        return $this->works[] = DB::table('works')->insertGetId(['primary_title' => $title, 'clean_title' => $title,
            'slug' => 'test-subject-' . uniqid(), 'volume_number' => 99, 'page_start' => 1]);
    }

    public function test_known_subjects_and_reviewed_phrases_are_linked_once()
    {
        $husayn = $this->person('حسین بن علی (ع)، امام سوم');
        $vali = $this->person('کرمانی آزمایشی، نعمت الله ولی');
        $maqtal = $this->work('مقتل الحسین (ع)');
        $sharh = $this->work('شرح حال شاه نعمت الله ولی آزمایشی');
        $other = $this->work('شرح حال کسی دیگر آزمایشی');
        file_put_contents($this->file, json_encode(['phrases' => ['شاه نعمت الله ولی آزمایشی' => 'کرمانی آزمایشی، نعمت الله ولی']], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file])->assertSuccessful();
        $this->assertSame(0, DB::table('work_people')->whereIn('work_id', $this->works)->count());

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file, '--apply' => true])->assertSuccessful();
        $row = DB::table('work_people')->where('work_id', $maqtal)->sole();
        $this->assertSame(['subject', $husayn, 'مقتل'], [$row->role, (int) $row->person_id, $row->label_raw]);
        $this->assertSame($vali, (int) DB::table('work_people')->where('work_id', $sharh)->value('person_id'));
        $this->assertSame(0, DB::table('work_people')->where('work_id', $other)->count());

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file, '--apply' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('work_people')->whereIn('work_id', $this->works)->count());
        $this->assertTrue(Work::find($maqtal)->workPeople()->where('role', 'subject')->exists());
    }
}
