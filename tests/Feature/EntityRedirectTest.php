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

        // Clean up
        EntityRedirect::removeRedirect('people', $source->id);
        $target->delete();
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

        // Clean up
        EntityRedirect::removeRedirect('subjects', $source->id);
        $target->delete();
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

        // Clean up
        EntityRedirect::removeRedirect('people', $personA->id);
        EntityRedirect::removeRedirect('people', $personB->id);
        $personC->delete();
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

        // Clean up
        $source->delete();
        $target->delete();
    }
}
