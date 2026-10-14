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

class SplitCompoundPeopleTest extends TestCase
{
    protected array $works = [];
    protected array $peopleIds = [];
    protected string $decisions;
    protected string $overrides;
    protected string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'آزمونک' . random_int(10000, 99999);
        $this->decisions = tempnam(sys_get_temp_dir(), 'compound') . '.json';
        $this->overrides = tempnam(sys_get_temp_dir(), 'overrides') . '.json';
    }

    protected function tearDown(): void
    {
        $workIds = array_map(fn($w) => $w->id, $this->works);
        $this->peopleIds = array_merge($this->peopleIds, Person::where('name', 'like', "%{$this->tag}%")->pluck('id')->all());
        EntityRedirect::where('entity_type', 'people')->whereIn('source_id', $this->peopleIds)->delete();
        AuditLog::where('auditable_type', Person::class)->whereIn('auditable_id', $this->peopleIds)->delete();
        Work::whereIn('id', $workIds)->delete();
        Person::whereIn('id', $this->peopleIds)->delete();
        @unlink($this->decisions);
        @unlink($this->overrides);

        parent::tearDown();
    }

    protected function person(string $name): Person
    {
        $p = Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'test-compound-' . uniqid(), 'is_scribe' => true]);
        $this->peopleIds[] = $p->id;

        return $p;
    }

    protected function manuscriptBy(Person $scribe): Manuscript
    {
        $work = $this->works[] = Work::create([
            'primary_title' => 'اثر آزمایشی تفکیک ' . uniqid(),
            'clean_title' => 'اثر آزمایشی تفکیک',
            'slug' => 'test-compound-work-' . uniqid(),
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

    /** Commands always run with --only, so the test never touches real records in a shared database */
    protected function only(): string
    {
        return implode(',', $this->peopleIds);
    }

    protected function propose(array $overrides = [], array $merges = []): array
    {
        file_put_contents($this->overrides, json_encode(['entries' => $overrides, 'merges' => $merges], JSON_UNESCAPED_UNICODE));
        $this->artisan('fahares:split-compound-people', ['action' => 'propose', '--file' => $this->decisions, '--overrides' => $this->overrides, '--only' => $this->only()])
            ->assertSuccessful();

        return collect(json_decode(file_get_contents($this->decisions), true)['entries'])->keyBy('person_id')->all();
    }

    public function test_propose_classifies_and_apply_splits_with_notes_and_redirect()
    {
        $existing = $this->person("حسن بن علی {$this->tag}");
        $compound = $this->person("حسن بن علی {$this->tag} (جلد اول) و محمد بن حسین {$this->tag} (جلد دوم)");
        $others = $this->person("احمد بن جعفر {$this->tag} و دیگران");
        $single = $this->person("محمد {$this->tag} شیرازی الاصل و المسکن");
        $ms = $this->manuscriptBy($compound);
        $ms2 = $this->manuscriptBy($others);

        $entries = $this->propose();

        $this->assertEquals('split', $entries[$compound->id]['decision']);
        $this->assertEquals($existing->id, $entries[$compound->id]['parts'][0]['person_id']);
        $this->assertEquals('جلد اول', $entries[$compound->id]['parts'][0]['note']);
        $this->assertNull($entries[$compound->id]['parts'][1]['person_id']);
        $this->assertEquals('others', $entries[$others->id]['decision']);
        $this->assertTrue($entries[$others->id]['has_others']);
        $this->assertEquals('keep', $entries[$single->id]['decision']);

        $this->artisan('fahares:split-compound-people', ['action' => 'apply', '--file' => $this->decisions, '--only' => $this->only()])->assertSuccessful();

        $ms->refresh();
        $scribes = $ms->scribes()->get();
        $this->assertEquals($existing->id, $ms->scribe_id);
        $this->assertEquals([$existing->id], [$scribes[0]->id]);
        $this->assertEquals("محمد بن حسین {$this->tag}", $scribes[1]->name);
        $this->assertEquals(['جلد اول', 'جلد دوم'], $scribes->pluck('pivot.note')->all());
        $this->assertStringContainsString(' و ', $ms->scribe_name, 'the catalog text is kept');
        $this->assertNull(Person::find($compound->id));
        $this->assertEquals($existing->id, EntityRedirect::resolveTargetId('people', $compound->id));
        $this->assertEquals(1, $existing->fresh()->manuscripts_count);

        $this->assertTrue($ms2->fresh()->has_other_scribes);
        $this->assertEquals("احمد بن جعفر {$this->tag}", $ms2->fresh()->scribe->name);
        $this->assertNotNull(Person::find($single->id), 'a single person is left alone');

        // Re-running changes nothing
        $this->artisan('fahares:split-compound-people', ['action' => 'apply', '--file' => $this->decisions, '--only' => $this->only()])
            ->expectsOutputToContain('split 0 records');
    }

    public function test_override_replaces_heuristic_reading()
    {
        $compound = $this->person("خادم {$this->tag} و خطیب روضه");
        $entries = $this->propose([[
            'person_id' => $compound->id, 'name' => $compound->name, 'decision' => 'keep', 'has_others' => false, 'parts' => [], 'comment' => 'یک نفر',
        ]]);

        $this->assertEquals('keep', $entries[$compound->id]['decision']);
        $this->assertEquals('یک نفر', $entries[$compound->id]['reason']);
    }


    public function test_reviewed_decision_keeps_unidentified_names_as_text_and_moves_extra_details()
    {
        $kept = $this->person("مؤلف شناخته {$this->tag}");
        $duplicate = $this->person("مؤلف شناخته‌ی تکراری {$this->tag}");
        $compound = $this->person("مهدی {$this->tag} جهت آقا محمد علی و مؤلف شناخته {$this->tag}");
        $edited = $this->person("شاهرخ خان {$this->tag} مین باشی و فرزند سرتیپ");
        $ms = $this->manuscriptBy($compound);
        $ms2 = $this->manuscriptBy($edited);

        $this->propose([
            [
                'person_id' => $compound->id, 'name' => $compound->name, 'decision' => 'split', 'has_others' => false,
                'parts' => [
                    ['name' => "مهدی {$this->tag}", 'note' => null, 'text' => true],
                    ['name' => "مؤلف شناخته‌ی تکراری {$this->tag}", 'note' => 'جلد دوم', 'person_id' => $duplicate->id, 'set' => ['is_translator' => true]],
                ],
                'manuscript_set' => ['commissioned_by' => 'آقا محمد علی', 'is_autograph' => true],
            ],
            [
                'person_id' => $edited->id, 'name' => $edited->name, 'decision' => 'edit', 'parts' => [],
                'person_set' => ['name' => "شاهرخ خان {$this->tag}", 'bio_notes' => 'مین‌باشی، فرزند سرتیپ'],
            ],
        ], [
            ['source_id' => $duplicate->id, 'source_name' => $duplicate->name, 'target_id' => $kept->id, 'target_name' => $kept->name],
        ]);

        $this->artisan('fahares:split-compound-people', ['action' => 'apply', '--file' => $this->decisions, '--only' => $this->only()])
            ->assertSuccessful();

        // The duplicate was merged first, so the part linked to it lands on the kept record
        $this->assertNull(Person::find($duplicate->id));
        $ms->refresh();
        $entries = $ms->scribeEntries()->with('person')->get();
        $this->assertEquals([null, $kept->id], $entries->pluck('person_id')->sortBy(fn($v) => $v ?? 0)->values()->all());
        $this->assertEquals($kept->id, $ms->scribe_id, 'the linked person is the primary scribe');
        $this->assertEquals("مهدی {$this->tag}", $entries->firstWhere('person_id', null)->name);
        $this->assertEquals('جلد دوم', $entries->firstWhere('person_id', $kept->id)->note);
        $this->assertEquals('آقا محمد علی', $ms->commissioned_by);
        $this->assertTrue($ms->is_autograph);
        $this->assertTrue($kept->fresh()->is_translator);
        $this->assertNull(Person::find($compound->id));
        $this->assertEquals(1, AuditLog::where('auditable_id', $kept->id)->where('action', 'merge')->count(), 'merge is logged and can be rolled back');

        $this->assertEquals("شاهرخ خان {$this->tag}", $edited->fresh()->name);
        $this->assertEquals('مین‌باشی، فرزند سرتیپ', $edited->fresh()->bio_notes);
        $this->assertEquals("شاهرخ خان {$this->tag}", $ms2->fresh()->scribe_name);

        $this->get(route('manuscripts.show', $ms->getRouteKey()))->assertOk()
            ->assertSee("مهدی {$this->tag}")->assertSee('(جلد دوم)', false);
    }
}
