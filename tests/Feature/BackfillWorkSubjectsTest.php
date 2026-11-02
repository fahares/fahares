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

    public function test_a_missing_record_of_the_fixed_list_is_made_once_and_a_listed_title_is_linked()
    {
        DB::table('people')->where('name', 'محمد بن علی (ع)، امام نهم')->delete();
        $listed = $this->work('پیشوای بزرگوار آزمایشی امام نهم');
        file_put_contents($this->file, json_encode(['phrases' => [], 'titles' => ['پیشوای بزرگوار آزمایشی امام نهم' => 'محمد بن علی (ع)، امام نهم']], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file])->assertSuccessful();
        $this->assertFalse(DB::table('people')->where('name', 'محمد بن علی (ع)، امام نهم')->exists());

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file, '--apply' => true])->assertSuccessful();
        $imam = DB::table('people')->where('name', 'محمد بن علی (ع)، امام نهم')->sole();
        $this->people[] = $imam->id;
        $this->assertSame([220, 3], [(int) $imam->death_year_hijri, (int) $imam->century_hijri]);
        $row = DB::table('work_people')->where('work_id', $listed)->sole();
        $this->assertSame(['subject', (int) $imam->id, null], [$row->role, (int) $row->person_id, $row->label_raw]);

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('people')->where('name', 'محمد بن علی (ع)، امام نهم')->count());
        $this->assertSame(1, DB::table('work_people')->where('work_id', $listed)->count());
    }

    public function test_a_row_the_rules_no_longer_give_is_pruned_but_one_they_give_is_kept()
    {
        $husayn = $this->person('حسین بن علی (ع)، امام سوم');
        $work = $this->work('وقایع آزمایشی پس از خاقان');
        DB::table('works')->where('id', $work)->update(['description' => 'بخشی از کتابی است که پس از وفات امام حسین نوشته شده است']);
        $stale = DB::table('work_people')->insertGetId(['work_id' => $work, 'person_id' => $husayn, 'role' => 'subject', 'position' => 0]);
        $given = $this->work('مقتل الحسین');
        $kept = DB::table('work_people')->insertGetId(['work_id' => $given, 'person_id' => $husayn, 'role' => 'subject', 'position' => 0]);
        file_put_contents($this->file, json_encode(['phrases' => []]));

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file, '--prune' => true])->expectsOutputToContain('pruned')->assertSuccessful();
        $this->assertTrue(DB::table('work_people')->where('id', $stale)->exists());

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file, '--prune' => true, '--apply' => true])->assertSuccessful();
        $this->assertFalse(DB::table('work_people')->where('id', $stale)->exists());
        $this->assertTrue(DB::table('work_people')->where('id', $kept)->exists());
        $this->assertSame(1, \App\Models\AuditLog::where('auditable_type', Work::class)->where('auditable_id', $work)->count());
        \App\Models\AuditLog::where('auditable_type', Work::class)->where('auditable_id', $work)->delete();
    }

    public function test_known_subjects_and_reviewed_phrases_are_linked_once()
    {
        $husayn = $this->person('حسین بن علی (ع)، امام سوم');
        $vali = $this->person('کرمانی آزمایشی، نعمت الله ولی');
        $maqtal = $this->work('مقتل الحسین (ع)');
        $sharh = $this->work('شرح حال شاه نعمت الله ولی آزمایشی');
        $other = $this->work('شرح حال کسی دیگر آزمایشی');
        $described = $this->work('قصیده آزمایشی');
        DB::table('works')->where('id', $described)->update(['description' => 'قصیده‌ای است در مدح حضرت امام حسین (ع) و یاران او. در باب دوم احوال شاه نعمت الله ولی آزمایشی.']);
        $chapter = $this->work('کتاب آزمایشی فصل‌دار');
        DB::table('works')->where('id', $chapter)->update(['description' => 'کتابی است مفصل در فقه استدلالی در ده باب و یک مقدمه و یک خاتمه و چند فصل که در آخر آن مرثیه امام حسین آمده است.']);
        file_put_contents($this->file, json_encode(['phrases' => ['شاه نعمت الله ولی آزمایشی' => 'کرمانی آزمایشی، نعمت الله ولی']], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file])->assertSuccessful();
        $this->assertSame(0, DB::table('work_people')->whereIn('work_id', $this->works)->count());

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file, '--apply' => true])->assertSuccessful();
        $row = DB::table('work_people')->where('work_id', $maqtal)->sole();
        $this->assertSame(['subject', $husayn, 'مقتل'], [$row->role, (int) $row->person_id, $row->label_raw]);
        $this->assertSame($vali, (int) DB::table('work_people')->where('work_id', $sharh)->value('person_id'));
        $this->assertSame(0, DB::table('work_people')->where('work_id', $other)->count());
        // The description's opening names its subject; a later sentence or chapter does not
        $this->assertSame([$husayn], DB::table('work_people')->where('work_id', $described)->pluck('person_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('مدح', DB::table('work_people')->where('work_id', $described)->value('label_raw'));
        $this->assertSame(0, DB::table('work_people')->where('work_id', $chapter)->count());

        $this->artisan('fahares:backfill-work-subjects', ['--decisions' => $this->file, '--apply' => true])->assertSuccessful();
        $this->assertSame(3, DB::table('work_people')->whereIn('work_id', $this->works)->count());
        $this->assertTrue(Work::find($maqtal)->workPeople()->where('role', 'subject')->exists());
    }
}
