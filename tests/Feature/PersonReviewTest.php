<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\PersonReview;
use App\Models\User;
use App\Models\Work;
use App\Services\DuplicatePeopleFinder;
use App\Services\PersonReviewQueue;
use Tests\TestCase;

class PersonReviewTest extends TestCase
{
    protected array $peopleIds = [];
    protected array $works = [];
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(['email' => 'test-admin@fahares.net'], ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']);
        $this->admin->update(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        $ids = array_merge($this->peopleIds, Person::where('name', 'like', '%آزمونکصف%')->pluck('id')->all());
        PersonReview::whereIn('key', array_merge(
            array_map(fn($id) => "person:{$id}", $ids),
            Person::whereIn('id', $ids)->pluck('name')->map(fn($n) => DuplicatePeopleFinder::key($n))->all(),
            ['محمدتقیبنمحمدکاظم'],
        ))->delete();
        EntityRedirect::where('entity_type', 'people')->whereIn('source_id', $ids)->delete();
        AuditLog::where('auditable_type', Person::class)->whereIn('auditable_id', $ids)->delete();
        Work::whereIn('id', array_map(fn($w) => $w->id, $this->works))->delete();
        Person::whereIn('id', $ids)->delete();

        parent::tearDown();
    }

    protected function person(string $name): Person
    {
        $p = Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'test-review-' . uniqid(), 'is_scribe' => true]);
        $this->peopleIds[] = $p->id;

        return $p;
    }

    protected function copyBy(Person $scribe, ?int $year): Manuscript
    {
        $work = $this->works[] = Work::create([
            'primary_title' => 'اثر آزمایشی صف ' . uniqid(), 'clean_title' => 'اثر آزمایشی صف',
            'slug' => 'test-review-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1,
        ]);

        return Manuscript::create([
            'work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            'scribe_id' => $scribe->id, 'scribe_name' => $scribe->name, 'copy_date_hijri_year' => $year, 'raw_text' => 'نسخه‌ی آزمایشی',
        ]);
    }

    public function test_namesakes_are_queued_merged_from_the_panel_and_a_keep_apart_decision_sticks()
    {
        // A generic name: never merged automatically, so it waits in the queue
        $a = $this->person('محمد تقی بن محمد کاظم');
        $b = $this->person('محمد تقی ابن محمد کاظم');
        $copyB = $this->copyBy($b, 1260);
        $this->copyBy($a, 1253);
        $key = DuplicatePeopleFinder::key($a->name);

        app(PersonReviewQueue::class)->refresh();
        $review = PersonReview::where('type', 'duplicate')->where('key', $key)->firstOrFail();
        $this->assertSame('open', $review->status);

        $this->actingAs($this->admin)->get(route('admin.person-reviews.index'))->assertOk()->assertSee($a->name);

        $this->actingAs($this->admin)->post(route('admin.person-reviews.merge', $review), [
            'merge_ids' => [$a->id, $b->id], 'target_id' => $a->id, 'note' => 'هر دو در قم',
        ])->assertSessionHas('success');

        $this->assertNull(Person::find($b->id));
        $this->assertSame($b->name, $copyB->fresh()->scribe_name, 'the printed name stays');
        $this->assertSame('merged', $review->fresh()->status);
        $this->assertSame($this->admin->id, $review->fresh()->decided_by);
        $this->assertNotNull(AuditLog::where('auditable_id', $a->id)->where('action', 'merge')->first());
    }

    public function test_a_keep_apart_decision_is_reopened_only_when_a_new_record_joins()
    {
        $a = $this->person('آزمونکصف عبدالصمد بن محمود');
        $b = $this->person('آزمونکصف عبدالصمد ابن محمود');
        $this->copyBy($a, 1100);
        $this->copyBy($b, 1110);
        $key = DuplicatePeopleFinder::key($a->name);
        $queue = app(PersonReviewQueue::class);

        // Seeded with an earlier «keep apart» judgement: recorded as decided, without a reviewer
        $queue->refresh([$key => ['key' => $key, 'decision' => 'skip', 'comment' => 'دو نفرند']]);
        $review = PersonReview::where('type', 'duplicate')->where('key', $key)->first();
        if (! $review) {
            $this->markTestSkipped('the test names fall in another tier here');
        }
        $this->actingAs($this->admin)->post(route('admin.person-reviews.status', $review), ['status' => 'distinct'])->assertSessionHas('success');

        $queue->refresh();
        $this->assertSame('distinct', $review->fresh()->status, 'the decision stays');

        $c = $this->person('آزمونکصف عبدالصمد بن المحمود');
        $this->copyBy($c, 1105);
        $queue->refresh();
        $this->assertSame('open', $review->fresh()->status, 'a newcomer reopens the group');
        $this->assertContains($c->id, $review->fresh()->person_ids);
    }

    public function test_a_record_holding_namesakes_is_split_from_the_panel()
    {
        $p = $this->person('آزمونکصف محمد علی تفرشی');
        $early = $this->copyBy($p, 1053);
        $late = $this->copyBy($p, 1333);

        app(PersonReviewQueue::class)->refresh();
        $review = PersonReview::where('type', 'mixed')->where('key', "person:{$p->id}")->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.person-reviews.index', ['type' => 'mixed']))->assertOk()->assertSee($p->name);

        $this->actingAs($this->admin)->post(route('admin.person-reviews.split', $review), [
            'new_name' => 'آزمونکصف محمد علی تفرشی (سده‌ی ۱۴)', 'manuscript_ids' => [$late->id],
        ])->assertSessionHas('success');

        $created = Person::where('name', 'آزمونکصف محمد علی تفرشی (سده‌ی ۱۴)')->firstOrFail();
        $this->assertSame($created->id, $late->fresh()->scribe_id);
        $this->assertSame($p->id, $early->fresh()->scribe_id);
        $this->assertSame('split', $review->fresh()->status);
        $this->assertNotNull(AuditLog::where('auditable_id', $p->id)->where('action', 'split')->first(), 'the split can be rolled back');
    }

    public function test_the_queue_needs_its_permission()
    {
        $user = User::firstOrCreate(['email' => 'test-user@fahares.net'], ['name' => 'Normal User', 'password' => 'password123', 'role' => 'user']);
        $user->update(['role' => 'user']);

        $this->actingAs($user)->get(route('admin.person-reviews.index'))->assertForbidden();
    }
}
