<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\Person;
use App\Models\Subject;
use App\Models\User;
use Tests\TestCase;

class EntityRedirectTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'test-admin@fahares.net'],
            ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']
        );
        $this->admin->role = 'admin';
        $this->admin->save();
    }

    public function test_merged_person_redirects_with_301_to_target()
    {
        $source = Person::create([
            'name' => 'پدیدآور مبدأ تست ریدایرکت ' . uniqid(),
            'normalized_name' => 'پدیداور مبدا تست ریدایرکت',
            'slug' => 'src-person-redir-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);

        $target = Person::create([
            'name' => 'پدیدآور مقصد تست ریدایرکت ' . uniqid(),
            'normalized_name' => 'پدیداور مقصد تست ریدایرکت',
            'slug' => 'trg-person-redir-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);

        // Merge source into target
        $this->actingAs($this->admin)->post('/admin/people/merge', [
            'source_id' => $source->id,
            'target_id' => $target->id,
        ]);

        $this->assertNull(Person::find($source->id));

        // Visit deleted source URL as guest
        $response = $this->get("/people/{$source->id}");
        $response->assertStatus(301);
        $response->assertRedirect(route('people.show', $target->getRouteKey()));
    }

    public function test_merged_subject_redirects_with_301_to_target()
    {
        $source = Subject::create([
            'name' => 'موضوع مبدأ تست ریدایرکت ' . uniqid(),
            'slug' => 'src-subj-redir-' . uniqid(),
            'works_count' => 0,
        ]);

        $target = Subject::create([
            'name' => 'موضوع مقصد تست ریدایرکت ' . uniqid(),
            'slug' => 'trg-subj-redir-' . uniqid(),
            'works_count' => 0,
        ]);

        // Merge source into target
        $this->actingAs($this->admin)->post('/admin/subjects/merge', [
            'source_subject_id' => $source->id,
            'target_subject_id' => $target->id,
        ]);

        $this->assertNull(Subject::find($source->id));

        // Visit deleted source URL as guest
        $response = $this->get("/subjects/{$source->id}");
        $response->assertStatus(301);
        $response->assertRedirect(route('subjects.show', $target->getRouteKey()));
    }

    public function test_chained_redirects_resolve_to_latest_target()
    {
        $personA = Person::create([
            'name' => 'شخص اول زنجیره ' . uniqid(),
            'normalized_name' => 'شخص اول زنجیره',
            'slug' => 'chain-a-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);

        $personB = Person::create([
            'name' => 'شخص دوم زنجیره ' . uniqid(),
            'normalized_name' => 'شخص دوم زنجیره',
            'slug' => 'chain-b-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);

        $personC = Person::create([
            'name' => 'شخص سوم زنجیره ' . uniqid(),
            'normalized_name' => 'شخص سوم زنجیره',
            'slug' => 'chain-c-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);

        // Merge A into B
        $this->actingAs($this->admin)->post('/admin/people/merge', [
            'source_id' => $personA->id,
            'target_id' => $personB->id,
        ]);

        // Merge B into C
        $this->actingAs($this->admin)->post('/admin/people/merge', [
            'source_id' => $personB->id,
            'target_id' => $personC->id,
        ]);

        // Direct request to A should redirect straight to C
        $responseA = $this->get("/people/{$personA->id}");
        $responseA->assertStatus(301);
        $responseA->assertRedirect(route('people.show', $personC->getRouteKey()));

        // Direct request to B should also redirect straight to C
        $responseB = $this->get("/people/{$personB->id}");
        $responseB->assertStatus(301);
        $responseB->assertRedirect(route('people.show', $personC->getRouteKey()));
    }

    public function test_rollback_merge_removes_redirect_and_restores_page()
    {
        $source = Person::create([
            'name' => 'شخص مبدأ تست رول‌بک ریدایرکت ' . uniqid(),
            'normalized_name' => 'شخص مبدا تست رول‌بک ریدایرکت',
            'slug' => 'rb-src-redir-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);

        $target = Person::create([
            'name' => 'شخص مقصد تست رول‌بک ریدایرکت ' . uniqid(),
            'normalized_name' => 'شخص مقصد تست رول‌بک ریدایرکت',
            'slug' => 'rb-trg-redir-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);

        // 1. Merge
        $this->actingAs($this->admin)->post('/admin/people/merge', [
            'source_id' => $source->id,
            'target_id' => $target->id,
        ]);

        $this->get("/people/{$source->id}")->assertStatus(301);

        // 2. Find merge log and rollback
        $log = AuditLog::where('auditable_type', Person::class)
            ->where('auditable_id', $target->id)
            ->where('action', 'merge')
            ->latest('id')
            ->first();

        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback");

        // 3. Source is restored and redirect is gone!
        $this->assertNotNull(Person::find($source->id));
        $this->assertNull(EntityRedirect::where('entity_type', 'people')->where('source_id', $source->id)->first());

        $restoredResponse = $this->get("/people/{$source->id}");
        // Follow redirect to canonical slug
        $restoredResponse->assertRedirect(route('people.show', $source->getRouteKey()));
        $followResponse = $this->get(route('people.show', $source->getRouteKey()));
        $followResponse->assertStatus(200);
        $followResponse->assertSee($source->name);
    }

    /** Step 14.113: the removed person's open suggestions follow the kept one, and an undo takes them back */
    public function test_a_person_merge_carries_open_suggestions_and_its_undo_takes_them_back()
    {
        $make = fn (string $n) => Person::create(['name' => $n . ' ' . uniqid(), 'normalized_name' => $n, 'slug' => 'sg-' . uniqid(), 'works_count' => 0, 'manuscripts_count' => 0]);
        $source = $make('شخص مبدأ پیشنهاد');
        $target = $make('شخص مقصد پیشنهاد');
        $suggest = fn (string $status) => \App\Models\FieldSuggestion::create(['suggestable_type' => Person::class, 'suggestable_id' => $source->id,
            'kind' => 'field', 'field_name' => 'bio_notes', 'suggested_value' => 'یادداشت', 'status' => $status]);
        $open = $suggest('pending');
        $closed = $suggest('rejected');

        $this->actingAs($this->admin)->post('/admin/people/merge', ['source_id' => $source->id, 'target_id' => $target->id]);
        $this->assertSame($target->id, $open->fresh()->suggestable_id);
        $this->assertSame($source->id, $closed->fresh()->suggestable_id);

        $log = AuditLog::where('auditable_type', Person::class)->where('auditable_id', $target->id)->where('action', 'merge')->latest('id')->first();
        $this->assertSame([$open->id], $log->old_values['suggestion_ids']);

        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback");
        $this->assertNotNull(Person::find($source->id));
        $this->assertSame($source->id, $open->fresh()->suggestable_id);
    }

    /** Step 14.193: the removed person's lives follow the kept one, a double of the same entry is dropped, and an undo restores both */
    public function test_a_person_merge_carries_lives_drops_doubles_and_its_undo_restores_them()
    {
        $make = fn (string $n) => Person::create(['name' => $n . ' ' . uniqid(), 'normalized_name' => $n, 'slug' => 'lv-' . uniqid(), 'works_count' => 0, 'manuscripts_count' => 0]);
        $source = $make('شخص مبدأ زندگی‌نامه');
        $target = $make('شخص مقصد زندگی‌نامه');
        $code = 'merge-lives-' . uniqid();
        $book = \App\Models\Source::create(['code' => $code, 'kind' => \App\Models\Source::KIND_BIOGRAPHICAL, 'rank' => 30, 'title' => 'الأعلام', 'short_name' => 'الأعلام']);
        $volume = \App\Models\SourceVolume::create(['source_id' => $book->id, 'volume_number' => 1, 'facsimile_code' => $code]);
        $item = fn (string $key) => \App\Models\SourceItem::create(['source_id' => $book->id, 'source_volume_id' => $volume->id, 'volume_number' => 1,
            'item_key' => $key, 'position' => 1, 'kind' => 'entry', 'page_start' => 1, 'page_end' => 1, 'title' => 'x', 'title_normalized' => 'x', 'text' => 'x', 'text_status' => 'auto']);
        $shared = $item('1#1');
        $own = $item('2#1');
        $tie = fn (Person $p, $it, string $status, int $n) => \App\Models\SourceEntry::create(['citable_type' => Person::class, 'citable_id' => $p->id,
            'source_id' => $book->id, 'source_item_id' => $it->id, 'volume_number' => 1, 'page_start' => 1, 'role' => 'entry', 'origin' => 'match',
            'status' => $status, 'legacy_type' => 'test_life', 'legacy_id' => $p->id * 10 + $n, 'legacy_index' => 0]);
        $double = $tie($source, $shared, 'unverified', 1);
        $verified = $tie($target, $shared, 'verified', 1);
        $moved = $tie($source, $own, 'unverified', 2);

        $this->actingAs($this->admin)->post('/admin/people/merge', ['source_id' => $source->id, 'target_id' => $target->id]);
        $this->assertSame($target->id, $moved->fresh()->citable_id);
        $this->assertNull($double->fresh());
        $this->assertSame($target->id, $verified->fresh()->citable_id);

        $log = AuditLog::where('auditable_type', Person::class)->where('auditable_id', $target->id)->where('action', 'merge')->latest('id')->first();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback");
        $this->assertSame($source->id, $moved->fresh()->citable_id);
        $this->assertSame($source->id, $double->fresh()?->citable_id);
        $this->assertSame($target->id, $verified->fresh()->citable_id);
    }
}
