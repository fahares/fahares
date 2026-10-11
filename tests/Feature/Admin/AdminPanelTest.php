<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\ScholarlyAnnotation;
use App\Models\Subject;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    protected User $admin;
    protected User $normalUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'test-admin@fahares.net'],
            ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']
        );
        $this->admin->role = 'admin';
        $this->admin->save();

        $this->normalUser = User::firstOrCreate(
            ['email' => 'test-user@fahares.net'],
            ['name' => 'Normal User', 'password' => 'password123', 'role' => 'user']
        );
        $this->normalUser->role = 'user';
        $this->normalUser->save();
    }

    public function test_guest_is_redirected_from_admin()
    {
        $response = $this->get('/admin');
        $response->assertRedirect('/login');
    }

    public function test_normal_user_is_forbidden_from_admin()
    {
        $response = $this->actingAs($this->normalUser)->get('/admin');
        $response->assertStatus(403);
    }

    public function test_admin_can_access_dashboard()
    {
        $response = $this->actingAs($this->admin)->get('/admin');
        $response->assertStatus(200);
        $response->assertSee('مرکز فرماندهی پایگاه فهارس');
    }

    public function test_admin_can_view_suggestions()
    {
        $response = $this->actingAs($this->admin)->get('/admin/suggestions');
        $response->assertStatus(200);
        $response->assertSee('میز کار داوری و اصلاحات علمی');
    }

    public function test_admin_can_approve_suggestion()
    {
        // Pick an existing manuscript or create one
        $manuscript = Manuscript::first();
        if (! $manuscript) {
            $this->markTestSkipped('No manuscripts found in database.');
        }

        $suggestion = FieldSuggestion::create([
            'suggestable_type' => Manuscript::class,
            'suggestable_id' => $manuscript->id,
            'field_name' => 'script_names',
            'current_value' => $manuscript->script_names,
            'suggested_value' => 'نسخ کهن ممتاز',
            'rationale_citation' => 'مستند ترقیمه پایان نسخه',
            'guest_name' => 'پژوهشگر تستی',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)->post("/admin/suggestions/{$suggestion->id}/approve", [
            'applied_value' => 'نسخ کهن ممتاز و معرب',
            'revision_category' => 'scholarly_correction',
            'create_annotation' => 1,
            'admin_notes' => 'تایید شد بر اساس سند صفحه آخر',
        ]);

        $response->assertRedirect('/admin/suggestions');

        $suggestion->refresh();
        $this->assertEquals('approved', $suggestion->status);
        $this->assertEquals($this->admin->id, $suggestion->reviewed_by);

        $manuscript->refresh();
        $this->assertEquals('نسخ کهن ممتاز و معرب', $manuscript->script_names);

        $annotation = ScholarlyAnnotation::where('suggestion_id', $suggestion->id)->first();
        $this->assertNotNull($annotation);
        $this->assertEquals('نسخ کهن ممتاز و معرب', $annotation->corrected_value);

        $auditLog = AuditLog::where('auditable_type', Manuscript::class)
            ->where('auditable_id', $manuscript->id)
            ->where('action', 'verify')
            ->first();
        $this->assertNotNull($auditLog);
    }

    public function test_subject_merge_operation()
    {
        $source = Subject::create([
            'name' => 'موضوع آزمایشی مبدأ ' . uniqid(),
            'slug' => 'test-source-' . uniqid(),
            'works_count' => 0,
        ]);

        $target = Subject::create([
            'name' => 'موضوع آزمایشی مقصد ' . uniqid(),
            'slug' => 'test-target-' . uniqid(),
            'works_count' => 0,
        ]);

        $response = $this->actingAs($this->admin)->post('/admin/subjects/merge', [
            'source_subject_id' => $source->id,
            'target_subject_id' => $target->id,
        ]);

        $response->assertRedirect('/admin/subjects');
        $this->assertNull(Subject::find($source->id));
        $this->assertNotNull(Subject::find($target->id));

        // Clean up target
        $target->delete();
    }

    public function test_people_merge_operation()
    {
        $source = Person::create([
            'name' => 'پدیدآور تکراری ' . uniqid(),
            'normalized_name' => 'پدیداور تکراری',
            'slug' => 'test-source-person-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);

        $target = Person::create([
            'name' => 'پدیدآور اصلی ' . uniqid(),
            'normalized_name' => 'پدیداور اصلی',
            'slug' => 'test-target-person-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);

        $response = $this->actingAs($this->admin)->post('/admin/people/merge', [
            'source_id' => $source->id,
            'target_id' => $target->id,
        ]);

        $response->assertRedirect('/admin/people');
        $this->assertNull(Person::find($source->id));
        $this->assertNotNull(Person::find($target->id));

        // Source name is kept as a parallel name of the target
        $this->assertContains($source->name, $target->fresh()->aliases);

        // Clean up target
        $target->delete();
    }

    public function test_people_merge_adds_only_new_aliases()
    {
        $source = Person::create([
            'name' => 'مجلسي، محمد باقر',
            'normalized_name' => 'مجلسی، محمد باقر',
            'slug' => 'test-alias-source-' . uniqid(),
            'aliases' => ['علامه مجلسی', 'صاحب بحار'],
        ]);

        $target = Person::create([
            'name' => 'مجلسی، محمد باقر',
            'normalized_name' => 'مجلسی، محمد باقر',
            'slug' => 'test-alias-target-' . uniqid(),
            'aliases' => ['علامه مجلسي'],
        ]);

        $this->actingAs($this->admin)->post('/admin/people/merge', [
            'source_id' => $source->id,
            'target_id' => $target->id,
        ])->assertRedirect('/admin/people');

        // Spelling variants of existing names (ي/ی) are not duplicated
        $this->assertEquals(['علامه مجلسي', 'صاحب بحار'], $target->fresh()->aliases);

        $target->delete();
    }

    public function test_rollback_keeps_alias_shared_with_another_active_merge()
    {
        $target = Person::create(['name' => 'شخص مقصد', 'normalized_name' => 'شخص مقصد', 'slug' => 'test-shared-target-' . uniqid()]);
        $first = Person::create(['name' => 'صورت نخست', 'normalized_name' => 'صورت نخست', 'slug' => 'test-shared-a-' . uniqid(), 'aliases' => ['نام مشترک']]);
        $second = Person::create(['name' => 'صورت دوم', 'normalized_name' => 'صورت دوم', 'slug' => 'test-shared-b-' . uniqid(), 'aliases' => ['نام مشترک']]);

        foreach ([$first, $second] as $source) {
            $this->actingAs($this->admin)->post('/admin/people/merge', [
                'source_id' => $source->id,
                'target_id' => $target->id,
            ])->assertRedirect('/admin/people');
        }

        $firstLog = AuditLog::where('auditable_id', $target->id)->where('action', 'merge')
            ->where('auditable_type', Person::class)->orderBy('id')->first();

        $this->actingAs($this->admin)->post("/admin/audit-logs/{$firstLog->id}/rollback")
            ->assertSessionHas('success');

        // The first source's own name goes; the alias shared with the still-merged second source stays
        $this->assertEquals(['نام مشترک', 'صورت دوم'], $target->fresh()->aliases);

        Person::whereIn('id', [$first->id, $target->id])->delete();
    }

    public function test_annotation_toggle_public()
    {
        $annotation = ScholarlyAnnotation::create([
            'annotatable_type' => Subject::class,
            'annotatable_id' => 1,
            'field_name' => 'name',
            'original_fankha_value' => 'قدیم',
            'corrected_value' => 'جدید',
            'revision_category' => 'scholarly_correction',
            'citation_source' => 'سند پژوهشی',
            'is_public' => true,
        ]);

        $response = $this->actingAs($this->admin)->post("/admin/annotations/{$annotation->id}/toggle-public");
        $response->assertSessionHas('success');

        $annotation->refresh();
        $this->assertFalse($annotation->is_public);

        $annotation->delete();
    }

    public function test_admin_can_update_user_role()
    {
        $targetUser = User::create([
            'name' => 'کاربر آزمایشی ارتقا',
            'email' => 'upgrade-' . uniqid() . '@fahares.net',
            'password' => 'password123',
            'role' => 'user',
        ]);

        $response = $this->actingAs($this->admin)->put("/admin/users/{$targetUser->id}", [
            'name' => 'دبیر علمی جدید',
            'role' => 'editor',
            'affiliation' => 'دانشگاه تهران',
            'is_verified_scholar' => 1,
        ]);

        $response->assertRedirect('/admin/users');

        $targetUser->refresh();
        $this->assertEquals('editor', $targetUser->role);
        $this->assertEquals('دبیر علمی جدید', $targetUser->name);
        $this->assertTrue($targetUser->is_verified_scholar);

        $targetUser->delete();
    }

    public function test_admin_can_rollback_person_merge()
    {
        $source = Person::create([
            'name' => 'پدیدآور مبدأ جهت رول‌بک ' . uniqid(),
            'normalized_name' => 'پدیداور مبدا جهت رول‌بک',
            'slug' => 'test-source-rollback-' . uniqid(),
            'works_count' => 1,
            'manuscripts_count' => 1,
            'is_author' => true,
            'is_scribe' => true,
        ]);

        $target = Person::create([
            'name' => 'پدیدآور مقصد جهت رول‌بک ' . uniqid(),
            'normalized_name' => 'پدیداور مقصد جهت رول‌بک',
            'slug' => 'test-target-rollback-' . uniqid(),
            'works_count' => 0,
            'manuscripts_count' => 0,
            'aliases' => ['نام موازی پیشین'],
        ]);

        $work = Work::create([
            'primary_title' => 'اثر آزمایشی رول‌بک ' . uniqid(),
            'clean_title' => 'اثر آزمایشی رول‌بک',
            'slug' => 'work-rollback-' . uniqid(),
            'author_id' => $source->id,
            'author_name' => $source->name,
            'volume_number' => 1,
            'page_start' => 10,
        ]);

        $manuscript = Manuscript::create([
            'work_id' => $work->id,
            'sequence_number' => 1,
            'volume_number' => 1,
            'page_start' => 10,
            'scribe_id' => $source->id,
            'scribe_name' => $source->name,
        ]);

        // Execute merge
        $mergeResponse = $this->actingAs($this->admin)->post('/admin/people/merge', [
            'source_id' => $source->id,
            'target_id' => $target->id,
        ]);
        $mergeResponse->assertRedirect('/admin/people');

        $this->assertNull(Person::find($source->id));
        $this->assertEquals($target->id, $work->fresh()->author_id);
        $this->assertEquals($target->id, $manuscript->fresh()->scribe_id);

        // Find merge audit log
        $log = AuditLog::where('auditable_type', Person::class)
            ->where('auditable_id', $target->id)
            ->where('action', 'merge')
            ->latest('id')
            ->first();
        $this->assertNotNull($log);

        // Execute rollback
        $rollbackResponse = $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback");
        $rollbackResponse->assertSessionHas('success');

        // Verify restoration
        $restoredSource = Person::find($source->id);
        $this->assertNotNull($restoredSource);
        $this->assertEquals($source->name, $restoredSource->name);

        $this->assertEquals($source->id, $work->fresh()->author_id);
        $this->assertEquals($source->name, $work->fresh()->author_name);

        $this->assertEquals($source->id, $manuscript->fresh()->scribe_id);
        $this->assertEquals($source->name, $manuscript->fresh()->scribe_name);

        $this->assertEquals(1, $restoredSource->fresh()->works_count);
        $this->assertEquals(0, $target->fresh()->works_count);

        // Aliases added by the merge are removed; pre-existing ones stay
        $this->assertEquals(['نام موازی پیشین'], $target->fresh()->aliases);

        // Clean up
        $manuscript->delete();
        $work->delete();
        $restoredSource->delete();
        $target->delete();
    }

    public function test_admin_can_rollback_subject_merge()
    {
        $source = Subject::create([
            'name' => 'موضوع مبدأ رول‌بک ' . uniqid(),
            'slug' => 'test-source-sub-rb-' . uniqid(),
            'works_count' => 1,
        ]);

        $target = Subject::create([
            'name' => 'موضوع مقصد رول‌بک ' . uniqid(),
            'slug' => 'test-target-sub-rb-' . uniqid(),
            'works_count' => 0,
        ]);

        $child = Subject::create([
            'name' => 'موضوع فرزند ' . uniqid(),
            'slug' => 'test-child-sub-rb-' . uniqid(),
            'parent_id' => $source->id,
            'works_count' => 0,
        ]);

        $work = Work::create([
            'primary_title' => 'اثر موضوعی رول‌بک ' . uniqid(),
            'clean_title' => 'اثر موضوعی رول‌بک',
            'slug' => 'work-sub-rb-' . uniqid(),
            'volume_number' => 1,
            'page_start' => 20,
        ]);

        DB::table('subject_work')->insert([
            'subject_id' => $source->id,
            'work_id' => $work->id,
        ]);

        // Execute merge
        $mergeResponse = $this->actingAs($this->admin)->post('/admin/subjects/merge', [
            'source_subject_id' => $source->id,
            'target_subject_id' => $target->id,
        ]);
        $mergeResponse->assertRedirect('/admin/subjects');

        $this->assertNull(Subject::find($source->id));
        $this->assertEquals($target->id, $child->fresh()->parent_id);
        $this->assertTrue(DB::table('subject_work')->where('subject_id', $target->id)->where('work_id', $work->id)->exists());

        // Find merge audit log
        $log = AuditLog::where('auditable_type', Subject::class)
            ->where('auditable_id', $target->id)
            ->where('action', 'merge')
            ->latest('id')
            ->first();
        $this->assertNotNull($log);

        // Execute rollback
        $rollbackResponse = $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback");
        $rollbackResponse->assertSessionHas('success');

        // Verify restoration
        $restoredSource = Subject::find($source->id);
        $this->assertNotNull($restoredSource);
        $this->assertEquals($source->id, $child->fresh()->parent_id);

        $this->assertTrue(DB::table('subject_work')->where('subject_id', $source->id)->where('work_id', $work->id)->exists());
        $this->assertFalse(DB::table('subject_work')->where('subject_id', $target->id)->where('work_id', $work->id)->exists());

        // Clean up
        DB::table('subject_work')->where('work_id', $work->id)->delete();
        $child->delete();
        $restoredSource->delete();
        $target->delete();
        $work->delete();
    }

    public function test_admin_can_rollback_verify_suggestion()
    {
        $manuscript = Manuscript::first();
        if (! $manuscript) {
            $this->markTestSkipped('No manuscripts found in database.');
        }

        $originalScript = $manuscript->script_names;

        $suggestion = FieldSuggestion::create([
            'suggestable_type' => Manuscript::class,
            'suggestable_id' => $manuscript->id,
            'field_name' => 'script_names',
            'current_value' => $originalScript,
            'suggested_value' => 'خط نسخ کهن آزمایشی رول‌بک',
            'rationale_citation' => 'مستند استعلام خط',
            'guest_name' => 'پژوهشگر رول‌بک',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)->post("/admin/suggestions/{$suggestion->id}/approve", [
            'applied_value' => 'خط نسخ کهن آزمایشی رول‌بک',
            'revision_category' => 'scholarly_correction',
            'create_annotation' => 1,
            'admin_notes' => 'تایید موقت برای تست رول‌بک',
        ]);

        $manuscript->refresh();
        $this->assertEquals('خط نسخ کهن آزمایشی رول‌بک', $manuscript->script_names);

        $log = AuditLog::where('auditable_type', Manuscript::class)
            ->where('auditable_id', $manuscript->id)
            ->where('action', 'verify')
            ->latest('id')
            ->first();
        $this->assertNotNull($log);

        // Execute rollback
        $rollbackResponse = $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback");
        $rollbackResponse->assertSessionHas('success');

        $manuscript->refresh();
        $this->assertEquals($originalScript, $manuscript->script_names);

        $suggestion->refresh();
        $this->assertEquals('pending', $suggestion->status);

        $this->assertNull(ScholarlyAnnotation::where('suggestion_id', $suggestion->id)->first());

        // Clean up suggestion
        $suggestion->delete();
    }

    public function test_admin_can_search_people_for_merger()
    {
        $person = Person::create([
            'name' => 'شخص ویژه جستجو ' . uniqid(),
            'normalized_name' => 'شخص ویژه جستجو',
            'slug' => 'person-search-test-' . uniqid(),
            'works_count' => 5,
            'manuscripts_count' => 2,
            'is_author' => true,
        ]);

        // Search by numeric ID
        $responseId = $this->actingAs($this->admin)->getJson("/admin/people/search?q={$person->id}");
        $responseId->assertStatus(200);
        $responseId->assertJsonFragment([
            'id' => $person->id,
            'name' => $person->name,
            'works_count' => 5,
        ]);

        // Search by text query
        $responseText = $this->actingAs($this->admin)->getJson("/admin/people/search?q=" . urlencode($person->name));
        $responseText->assertStatus(200);
        $this->assertTrue(collect($responseText->json())->contains('id', $person->id));

        $person->delete();
    }

    public function test_admin_can_view_merge_page_with_preloaded_people()
    {
        $source = Person::create([
            'name' => 'منبع پیش‌بارگذاری ' . uniqid(),
            'normalized_name' => 'منبع پیش‌بارگذاری',
            'slug' => 'pre-src-' . uniqid(),
            'works_count' => 1,
            'manuscripts_count' => 0,
        ]);

        $target = Person::create([
            'name' => 'مقصد پیش‌بارگذاری ' . uniqid(),
            'normalized_name' => 'مقصد پیش‌بارگذاری',
            'slug' => 'pre-trg-' . uniqid(),
            'works_count' => 2,
            'manuscripts_count' => 0,
        ]);

        $response = $this->actingAs($this->admin)->get("/admin/people/merge?source_id={$source->id}&target_id={$target->id}");
        $response->assertStatus(200);
        $response->assertSee($source->name);
        $response->assertSee($target->name);

        $source->delete();
        $target->delete();
    }
}

