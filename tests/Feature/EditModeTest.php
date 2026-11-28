<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Suggestions\ProtectedColumns;
use Tests\TestCase;

/**
 * The edit mode of the record pages (backlog item 67, step 14.454): a reviewer edits a field in place through
 * admin.suggestions.direct-edit, which says what the field now holds and which log takes it back; the reviewer may
 * take back their own edit for a quarter of an hour.
 */
class EditModeTest extends TestCase
{
    private array $people = [];
    private array $users = [];

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Person::class)->whereIn('auditable_id', $this->people)->delete();
        Person::whereIn('id', $this->people)->delete();
        User::whereIn('id', $this->users)->delete();
        parent::tearDown();
    }

    private function person(): Person
    {
        $person = Person::create(['name' => 'شخص حالت ویرایش ' . uniqid(), 'normalized_name' => 'شخص حالت ویرایش', 'slug' => 'test-edit-mode-' . uniqid(), 'bio_notes' => 'متن کهنه']);
        $this->people[] = $person->id;

        return $person;
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'email_verified_at' => now()]);
        $this->users[] = $user->id;

        return $user;
    }

    private function edit(Person $person, string $value)
    {
        return $this->postJson(route('admin.suggestions.direct-edit'), ['type' => 'person', 'id' => $person->id, 'field_name' => 'bio_notes', 'suggested_value' => $value]);
    }

    public function test_the_edit_mode_is_offered_to_reviewers_only()
    {
        $person = $this->person();
        $this->actingAs($this->user('editor'))->get(route('people.show', $person))->assertOk()
            ->assertSee('<meta name="edit-mode" content="1">', false)->assertSee('data-edit-mode-toggle', false)->assertSee('data-inline-editor', false);
        $this->actingAs($this->user('user'))->get(route('people.show', $person))->assertOk()
            ->assertDontSee('name="edit-mode"', false)->assertDontSee('data-edit-mode-toggle', false);
    }

    public function test_an_edit_in_place_says_what_the_field_holds_and_can_be_taken_back()
    {
        $person = $this->person();
        $editor = $this->user('editor');
        $data = $this->actingAs($editor)->edit($person, "  متن تازه\r\n")->assertOk()->json();

        $this->assertSame('متن تازه', $person->fresh()->bio_notes);
        $this->assertSame(['current' => 'متن تازه', 'prefill' => 'متن تازه', 'pair' => null], $data['field']);
        $log = AuditLog::findOrFail($data['log_id']);
        $this->assertTrue($log->new_values['direct_edit']);
        // ...protected from the corpus sync like any reviewed correction
        $this->assertSame([], ProtectedColumns::for(Person::class)->strip($person->id, ['bio_notes' => 'متن پیکره']));

        // the same value again changes nothing, and there is nothing to take back
        $this->assertNull($this->edit($person, 'متن تازه')->assertOk()->json('log_id'));

        $this->postJson(route('admin.suggestions.direct-edit.undo', $log))->assertOk();
        $this->assertSame('متن کهنه', $person->fresh()->bio_notes);
        // ...once
        $this->postJson(route('admin.suggestions.direct-edit.undo', $log))->assertStatus(422);
    }

    public function test_only_ones_own_recent_edit_is_taken_back()
    {
        $person = $this->person();
        $first = $this->user('editor');
        $logId = $this->actingAs($first)->edit($person, 'متن یکم')->json('log_id');

        // someone else's edit, or a reader without the permission
        $this->actingAs($this->user('editor'))->postJson(route('admin.suggestions.direct-edit.undo', $logId))->assertForbidden();
        $this->actingAs($this->user('user'))->postJson(route('admin.suggestions.direct-edit.undo', $logId))->assertForbidden();

        // a later edit of the same field comes first
        $this->actingAs($first)->edit($person, 'متن دوم')->assertOk();
        $this->postJson(route('admin.suggestions.direct-edit.undo', $logId))->assertStatus(422);
        $this->assertSame('متن دوم', $person->fresh()->bio_notes);

        // after a quarter of an hour, the audit log undoes it
        $this->travel(16)->minutes();
        $last = AuditLog::where('auditable_type', Person::class)->where('auditable_id', $person->id)->latest('id')->first();
        $this->postJson(route('admin.suggestions.direct-edit.undo', $last))->assertStatus(422)->assertJsonFragment(['message' => 'زمان بازگردانی گذشته است؛ از لاگ ممیزی بازگردانید.']);
    }
}
