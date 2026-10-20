<?php

namespace Tests\Feature;

use App\Console\Commands\FindDuplicatePeopleCommand;
use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use Tests\TestCase;

class DuplicatePeopleTest extends TestCase
{
    protected array $peopleIds = [];
    protected array $works = [];
    protected string $tag;
    protected string $report;
    protected string $decisions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'آزمونکیکتا' . random_int(10000, 99999);
        $this->report = tempnam(sys_get_temp_dir(), 'dups') . '.json';
        $this->decisions = tempnam(sys_get_temp_dir(), 'dupdec') . '.json';
    }

    protected function tearDown(): void
    {
        $this->peopleIds = array_merge($this->peopleIds, Person::where('name', 'like', "%{$this->tag}%")->pluck('id')->all());
        EntityRedirect::where('entity_type', 'people')->whereIn('source_id', $this->peopleIds)->delete();
        AuditLog::where('auditable_type', Person::class)->whereIn('auditable_id', $this->peopleIds)->delete();
        Work::whereIn('id', array_map(fn($w) => $w->id, $this->works))->delete();
        Person::whereIn('id', $this->peopleIds)->delete();
        @unlink($this->report);
        @unlink($this->decisions);

        parent::tearDown();
    }

    protected function person(string $name, array $extra = []): Person
    {
        $p = Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'test-dup-' . uniqid(), 'is_scribe' => true] + $extra);
        $this->peopleIds[] = $p->id;

        return $p;
    }

    protected function copyBy(Person $scribe, ?int $year): Manuscript
    {
        $work = $this->works[] = Work::create([
            'primary_title' => 'اثر آزمایشی تکراری ' . uniqid(), 'clean_title' => 'اثر آزمایشی تکراری',
            'slug' => 'test-dup-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1,
        ]);

        return Manuscript::create([
            'work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            'scribe_id' => $scribe->id, 'scribe_name' => $scribe->name, 'copy_date_hijri_year' => $year, 'raw_text' => 'نسخه‌ی آزمایشی',
        ]);
    }

    /** The report limited to this test's groups, so a shared database never leaks into it */
    protected function reportGroups(): array
    {
        $this->artisan('fahares:find-duplicate-people', ['--out' => $this->report])->assertSuccessful();
        $all = json_decode(file_get_contents($this->report), true);
        $mine = array_values(array_filter($all['groups'], fn($g) => array_intersect(array_column($g['members'], 'id'), $this->peopleIds)));
        file_put_contents($this->report, json_encode(['groups' => $mine], JSON_UNESCAPED_UNICODE));

        return collect($mine)->keyBy('key')->all();
    }

    public function test_key_ignores_spelling_variants_and_the_inverted_form()
    {
        $this->assertSame(
            FindDuplicatePeopleCommand::key('محمد بن علی الدامغانی الجابرمی'),
            FindDuplicatePeopleCommand::key('دامغانی جابرمی، محمد ابن على')
        );
        $this->assertNotSame(FindDuplicatePeopleCommand::key('محمد بن علی'), FindDuplicatePeopleCommand::key('علی بن محمد'));
    }

    public function test_tiers_and_merge_keep_the_printed_names_and_can_be_rolled_back()
    {
        // Same distinctive name, copies a few years apart: merged automatically
        $a = $this->person("عبدالعلی بن علی نقی رنینی {$this->tag}");
        $b = $this->person("رنینی {$this->tag}، عبدالعلی بن علی نقی");
        $copyA = $this->copyBy($a, 1240);
        $copyB = $this->copyBy($b, 1245);
        // Same name, copies 170 years apart: never merged
        $c = $this->person("عبدالصمد بن محمود {$this->tag}");
        $d = $this->person("عبدالصمد ابن محمود {$this->tag}");
        $this->copyBy($c, 967);
        $this->copyBy($d, 1140);

        $groups = $this->reportGroups();
        $this->assertCount(2, $groups);
        $tiers = collect($groups)->pluck('tier', 'key')->all();
        $this->assertSame('likely', $tiers[FindDuplicatePeopleCommand::key($a->name)]);
        $this->assertSame('conflict', $tiers[FindDuplicatePeopleCommand::key($c->name)]);

        $this->artisan('fahares:merge-duplicate-people', ['--report' => $this->report, '--decisions' => $this->decisions])
            ->expectsOutputToContain('Merged 1 records into 1 people')
            ->assertSuccessful();

        $kept = Person::find($a->id) ?? Person::find($b->id);
        $gone = $kept->id === $a->id ? $b : $a;
        $this->assertNull(Person::find($gone->id));
        $this->assertEquals(2, $kept->fresh()->manuscripts_count);
        $this->assertSame($b->name, $copyB->fresh()->scribe_name, 'the printed scribe name is left as the catalog gives it');
        $this->assertSame($a->name, $copyA->fresh()->scribe_name);
        $this->assertNotNull(Person::find($c->id));
        $this->assertNotNull(Person::find($d->id));

        // Rolled back from the panel: the record returns and the printed names stay untouched
        $admin = User::firstOrCreate(['email' => 'test-admin@fahares.net'], ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']);
        $admin->update(['role' => 'admin']);
        $log = AuditLog::where('auditable_id', $kept->id)->where('action', 'merge')->latest('id')->firstOrFail();
        $this->assertTrue($log->old_values['kept_printed_names']);
        $this->actingAs($admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');

        $this->assertNotNull(Person::find($gone->id));
        $this->assertSame($b->name, $copyB->fresh()->scribe_name);
        $this->assertSame($a->name, $copyA->fresh()->scribe_name);
    }

    public function test_reviewed_group_merges_only_the_listed_records()
    {
        // A generic name, so no tag in it: the report is limited to this test's records instead
        $a = $this->person('محمد تقی بن محمد کاظم');
        $b = $this->person('محمد تقی ابن محمد کاظم');
        $c = $this->person('محمد تقی بن محمد الکاظم');
        $this->copyBy($a, 1253);
        $this->copyBy($b, 1260);
        $this->copyBy($c, 1262);

        $groups = $this->reportGroups();
        $key = FindDuplicatePeopleCommand::key($a->name);
        $this->assertNotSame('likely', $groups[$key]['tier'], 'a generic name is not merged without review');

        file_put_contents($this->decisions, json_encode(['groups' => [
            ['key' => $key, 'decision' => 'merge', 'merge_ids' => [$a->id, $b->id], 'target_id' => $a->id],
        ]], JSON_UNESCAPED_UNICODE));
        $this->artisan('fahares:merge-duplicate-people', ['--report' => $this->report, '--decisions' => $this->decisions])->assertSuccessful();

        $this->assertNotNull(Person::find($a->id));
        $this->assertNull(Person::find($b->id));
        $this->assertNotNull(Person::find($c->id), 'a record left out of the decision stays');
    }
}
