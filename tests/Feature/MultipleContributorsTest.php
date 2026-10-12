<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultipleContributorsTest extends TestCase
{
    protected array $works = [];
    protected array $peopleIds = [];
    protected string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'آزمونک' . random_int(10000, 99999);
    }

    protected function tearDown(): void
    {
        $workIds = array_map(fn($w) => $w->id, $this->works);
        $this->peopleIds = array_merge($this->peopleIds, Person::where('name', 'like', "%{$this->tag}%")->pluck('id')->all());
        EntityRedirect::where('entity_type', 'people')->whereIn('source_id', $this->peopleIds)->delete();
        AuditLog::where('auditable_type', Person::class)->whereIn('auditable_id', $this->peopleIds)->delete();
        Work::whereIn('id', $workIds)->delete();
        Person::whereIn('id', $this->peopleIds)->delete();

        parent::tearDown();
    }

    protected function person(string $name): Person
    {
        $p = Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'test-multi-' . uniqid(), 'is_scribe' => true]);
        $this->peopleIds[] = $p->id;

        return $p;
    }

    protected function manuscriptBy(Person $scribe): Manuscript
    {
        $work = $this->works[] = Work::create([
            'primary_title' => 'اثر آزمایشی چندکاتبه ' . uniqid(),
            'clean_title' => 'اثر آزمایشی چندکاتبه',
            'slug' => 'test-multi-work-' . uniqid(),
            'volume_number' => 1,
            'page_start' => 1,
        ]);

        return Manuscript::create([
            'work_id' => $work->id,
            'sequence_number' => 1,
            'volume_number' => 1,
            'page_start' => 1,
            'scribe_id' => $scribe->id,
            'scribe_name' => $scribe->name,
            'raw_text' => 'نسخه‌ی آزمایشی',
        ]);
    }

    public function test_primary_column_and_pivot_stay_in_step()
    {
        $a = $this->person("کاتب یکم {$this->tag}");
        $b = $this->person("کاتب دوم {$this->tag}");
        $ms = $this->manuscriptBy($a);

        $this->assertEquals([$a->id], $ms->scribes()->pluck('people.id')->all());

        $ms->update(['scribe_id' => $b->id]);
        $this->assertEquals([$b->id], $ms->scribes()->pluck('people.id')->all());
    }

    public function test_merge_and_rollback_keep_co_scribes()
    {
        $admin = User::firstOrCreate(['email' => 'test-admin@fahares.net'], ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']);
        $admin->update(['role' => 'admin']);

        $first = $this->person("کاتب نخست {$this->tag}");
        $second = $this->person("کاتب همکار {$this->tag}");
        $duplicate = $this->person("کاتب همکار تکراری {$this->tag}");
        $ms = $this->manuscriptBy($first);
        $ms->update(['scribe_name' => "{$first->name} و {$second->name}"]);
        DB::table('manuscript_scribe')->insert(['manuscript_id' => $ms->id, 'person_id' => $second->id, 'position' => 1, 'note' => 'جلد دوم']);

        $this->actingAs($admin)->post('/admin/people/merge', ['source_id' => $second->id, 'target_id' => $duplicate->id])
            ->assertRedirect('/admin/people');

        $ms->refresh();
        $this->assertEquals($first->id, $ms->scribe_id, 'the primary scribe is untouched');
        $this->assertEquals([$first->id, $duplicate->id], $ms->scribes()->pluck('people.id')->all());
        $this->assertEquals('جلد دوم', $ms->scribes()->get()[1]->pivot->note);
        $this->assertStringContainsString($first->name, $ms->scribe_name, 'a shared scribe name is not overwritten');
        $this->assertEquals(1, $duplicate->fresh()->manuscripts_count);

        $log = AuditLog::where('auditable_id', $duplicate->id)->where('action', 'merge')->latest('id')->firstOrFail();
        $this->actingAs($admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');

        $this->assertEquals([$first->id, $second->id], $ms->scribes()->pluck('people.id')->all());
        $this->assertEquals(0, $duplicate->fresh()->manuscripts_count);
        $this->assertEquals(1, Person::find($second->id)->manuscripts_count);
    }
}
