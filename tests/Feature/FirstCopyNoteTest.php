<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\ManuscriptNote;
use App\Models\ScholarlyAnnotation;
use App\Models\User;
use App\Models\Work;
use App\Suggestions\SuggestionRegistry;
use Tests\TestCase;

/**
 * The first typed note of a copy that has none (step 14.318): suggested or added by a reviewer at once, shown in its
 * section, and taken back from the audit log
 */
class FirstCopyNoteTest extends TestCase
{
    private User $admin;

    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::firstOrCreate(['email' => 'test-admin@fahares.net'], ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']);
        $this->admin->forceFill(['role' => 'admin', 'email_verified_at' => $this->admin->email_verified_at ?? now()])->save();
        $this->work = Work::create(['primary_title' => 'اثر آزمایشی نخستین یادداشت', 'clean_title' => 'اثر آزمایشی نخستین یادداشت',
            'slug' => 'first-note-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    protected function tearDown(): void
    {
        $ids = Manuscript::where('work_id', $this->work->id)->pluck('id');
        $suggestions = FieldSuggestion::where('suggestable_type', Manuscript::class)->whereIn('suggestable_id', $ids)->pluck('id');
        ScholarlyAnnotation::whereIn('suggestion_id', $suggestions)->delete();
        AuditLog::where('auditable_type', FieldSuggestion::class)->whereIn('auditable_id', $suggestions)->delete();
        FieldSuggestion::whereIn('id', $suggestions)->delete();
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $ids)->delete();
        ManuscriptNote::whereIn('manuscript_id', $ids)->delete();
        Manuscript::whereIn('id', $ids)->delete();
        $this->work->delete();
        parent::tearDown();
    }

    private function copy(array $attributes = []): Manuscript
    {
        return Manuscript::create($attributes + ['work_id' => $this->work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'shelfmark' => '2897/2']);
    }

    private function page(Manuscript $copy): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('manuscripts.show', ['id' => $copy->getRouteKey()]))->assertOk();
    }

    private function direct(Manuscript $copy, string $field, string $value): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/admin/suggestions/direct-edit', ['type' => 'manuscript', 'id' => $copy->id, 'field_name' => $field, 'suggested_value' => $value]);
    }

    private function rollback(AuditLog $log): void
    {
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
    }

    private function lastLog(Manuscript $copy): AuditLog
    {
        return AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $copy->id)->where('action', 'verify')->latest('id')->firstOrFail();
    }

    public function test_a_copy_without_a_note_takes_a_suggested_note_shows_it_and_gives_it_back()
    {
        $copy = $this->copy();

        // a note of every type can be added, and the old list of seals, which the page does not show, is no field
        $fields = SuggestionRegistry::recordFields($copy);
        $this->assertSame('افزودن یادداشت فهرست‌نگار', $fields['note_new_cataloguer']['label']);
        $this->assertArrayHasKey('note_new_seal', $fields);
        $this->assertArrayNotHasKey('note_new_other', $fields);
        $this->assertArrayNotHasKey('ownership_and_seals', $fields);

        // the page: one button, whose form asks the type, not a button a type
        $page = $this->page($copy);
        $page->assertSee('data-suggest-new-note', false)->assertSee('افزودن یادداشت')->assertSee('<optgroup label="مهرها، تملک‌ها و وقف‌نامه‌ها">', false)
            ->assertSee('<option value="note_new_cataloguer">فهرست‌نگار</option>', false)
            ->assertDontSee('data-suggest-field="note_new_', false)->assertDontSee('<option value="note_new_other">', false);

        $text = 'این نسخه همان است که در فهرست قدیم با شمارۀ ۴۱۲ آمده';
        $this->suggest($copy, 'note_new_cataloguer', $text)->assertOk();
        $suggestion = FieldSuggestion::where('suggestable_type', Manuscript::class)->where('suggestable_id', $copy->id)->where('status', 'pending')->sole();
        $this->actingAs($this->admin)->post("/admin/suggestions/{$suggestion->id}/approve", ['applied_value' => $text, 'revision_category' => 'scholarly_correction'])
            ->assertSessionHasNoErrors();

        $note = $copy->fresh()->notes->sole();
        $this->assertSame(['cataloguer', $text, 'editor', 1], [$note->type, $note->text, $note->typed_by, $note->position]);
        $this->assertSame('approved', $suggestion->fresh()->status);

        // its section, with its own buttons, and the one button for the sections the page still lacks
        $page = $this->page($copy->fresh());
        $page->assertSeeInOrder(['محتوای نسخه', 'فهرست‌نگار', $text])->assertSee('data-suggest-field="note_' . $note->id . '"', false)
            ->assertSee('data-suggest-field="note_new_description"', false)->assertSee('data-suggest-new-note', false)
            ->assertDontSee('data-suggest-field="note_new_seal"', false);

        // taken back from the audit log: the copy has no note again, and the suggestion waits again
        $this->rollback(AuditLog::activeForSuggestion($suggestion->id));
        $this->assertSame(0, ManuscriptNote::where('manuscript_id', $copy->id)->count());
        $this->assertSame('pending', $suggestion->fresh()->status);
        // (the section's title is still in the form's list of types)
        $this->page($copy->fresh())->assertDontSee($text)->assertDontSee('data-suggest-field="note_new_description"', false)->assertSee('data-suggest-new-note', false);
    }

    public function test_a_reviewer_adds_the_first_note_at_once_and_each_change_of_a_note_is_taken_back()
    {
        $copy = $this->copy();

        $this->direct($copy, 'note_new_seal', 'مهر بیضی «عبده محمدباقر»')->assertOk();
        $note = $copy->fresh()->notes->sole();
        $this->assertSame(['seal', 'editor'], [$note->type, $note->typed_by]);
        $this->assertFalse($copy->fresh()->keepsSealsList());
        $this->page($copy->fresh())->assertSeeInOrder(['مهرها، تملک‌ها و وقف‌نامه‌ها', 'مهر بیضی «عبده محمدباقر»'])
            ->assertSee('data-suggest-field="note_new_ownership"', false);
        $added = $this->lastLog($copy);

        // a removed note comes back as it was, with its id; a corrected one gets its text back
        $this->direct($copy, 'note_' . $note->id, '')->assertOk();
        $this->assertSame(0, ManuscriptNote::where('manuscript_id', $copy->id)->count());
        $this->rollback($this->lastLog($copy));
        $this->assertSame([$note->id, 'seal', 'مهر بیضی «عبده محمدباقر»', 1], array_values($copy->fresh()->notes->sole()->only(['id', 'type', 'text', 'position'])));
        $this->direct($copy, 'note_' . $note->id, 'مهر بیضی «عبده محمدباقر»، ۱۲۴۰')->assertOk();
        $this->rollback($this->lastLog($copy));
        $this->assertSame('مهر بیضی «عبده محمدباقر»', $note->fresh()->text);

        $this->rollback($added);
        $this->assertSame(0, ManuscriptNote::where('manuscript_id', $copy->id)->count());
        $this->assertTrue($copy->fresh()->keepsSealsList());
        $this->page($copy->fresh())->assertDontSee('عبده محمدباقر')->assertDontSee('data-suggest-field="note_new_ownership"', false);
    }

    public function test_a_copy_with_the_old_lists_keeps_them_beside_an_added_note_and_takes_no_note_of_provenance()
    {
        $old = ['editorial_notes' => ['یادداشت کهنۀ فهرست‌نگار'], 'ownership_and_seals' => ['مهر: فهرست کهنه'], 'residual_notes' => 'فوائد کهنه'];
        $copy = $this->copy(['metadata' => $old]);

        // the old list of seals is the field; no note of provenance is added beside it
        $fields = SuggestionRegistry::recordFields($copy);
        $this->assertArrayHasKey('ownership_and_seals', $fields);
        $this->assertArrayHasKey('note_new_cataloguer', $fields);
        foreach (ManuscriptNote::GROUPS['provenance']['types'] as $type) {
            $this->assertArrayNotHasKey('note_new_' . $type, $fields);
        }
        $this->assertNull(SuggestionRegistry::fieldOf($copy, 'note_new_seal'));
        $this->direct($copy, 'note_new_seal', 'مهر تازه')->assertStatus(422);
        $this->suggest($copy, 'note_new_seal', 'مهر تازه')->assertStatus(422);
        $this->page($copy)->assertSee('data-suggest-new-note', false)->assertSee('<option value="note_new_collation">', false)
            ->assertDontSee('<option value="note_new_seal">', false)->assertDontSee('<optgroup label="مهرها، تملک‌ها و وقف‌نامه‌ها">', false)
            ->assertSee('data-suggest-field="ownership_and_seals"', false);

        // a reviewer's note comes beside the three lists, which the import's notes never replaced here
        $this->direct($copy, 'note_new_cataloguer', 'یادداشت تازۀ بازبین')->assertOk();
        $copy = $copy->fresh();
        $this->assertTrue($copy->keepsOldNotes());
        $page = $this->page($copy);
        $page->assertSee('یادداشت تازۀ بازبین')->assertSee('یادداشت کهنۀ فهرست‌نگار')->assertSee('مهر: فهرست کهنه')->assertSee('فوائد کهنه')
            ->assertDontSee('data-suggest-field="note_new_seal"', false)->assertSee('data-suggest-field="note_new_scope"', false);

        // ...but a note of the import does take their place, a reviewer's correction of it too
        $imported = ManuscriptNote::create(['manuscript_id' => $copy->id, 'position' => 2, 'type' => 'contents', 'text' => 'فهرست اشعار', 'typed_by' => 'sonnet']);
        $this->assertFalse($copy->fresh()->keepsOldNotes());
        $this->direct($copy, 'note_' . $imported->id, 'فهرست اشعار دیوان')->assertOk();
        $copy = $copy->fresh();
        $this->assertSame(['editor', 'editor'], $copy->notes->pluck('typed_by')->all());
        $this->assertFalse($copy->keepsOldNotes());
        $this->page($copy)->assertSee('فهرست اشعار دیوان')->assertDontSee('یادداشت کهنۀ فهرست‌نگار')->assertDontSee('فوائد کهنه');
    }

    private function suggest(Manuscript $copy, string $field, string $value): \Illuminate\Testing\TestResponse
    {
        return $this->suggestJson(['type' => 'manuscript', 'id' => $copy->id, 'kind' => 'field', 'field_name' => $field, 'suggested_value' => $value,
            'rationale_citation' => 'نسخه را خودم دیده‌ام']);
    }
}
