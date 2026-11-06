<?php

namespace Tests\Feature;

use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A source's own page (/sources/{code}) takes suggestions like a record page (step 14.52): a correction of its title,
 * compiler, publisher, year or description, a general suggestion and a research note; a reviewer approves it onto the
 * source, and a suggestion filed on another page (a library's, as #106 was) converts to one on the source.
 */
class SourceSuggestionTest extends TestCase
{
    protected User $admin;

    protected User $researcher;

    protected Source $source;

    protected Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->admin = User::create(['name' => 'داور منبع', 'email' => uniqid('ss-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->researcher = User::create(['name' => 'پژوهشگر منبع', 'email' => uniqid('ss-') . '@example.test', 'password' => 'password123', 'role' => 'user']);
        $this->library = Library::create(['name' => 'کتابخانه‌ی آزمون منبع', 'city' => 'قم', 'slug' => 'ss-lib-' . uniqid()]);
        $this->source = Source::create(['code' => 'ss-cat-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست آزمون منبع',
            'library_id' => $this->library->id, 'volumes_count' => 2]);
        SourceVolume::create(['source_id' => $this->source->id, 'volume_number' => 1]);
    }

    public function test_the_source_page_has_the_suggestion_desk_and_a_button_per_field()
    {
        $page = $this->actingAs($this->researcher)->get(route('sources.show', $this->source->code))->assertOk()
            ->assertSee('پیشنهاد اصلاح')->assertSee('suggestDesk', false)->assertSee('یادداشت‌ها و منابع پژوهشی');
        foreach (['title', 'compiler', 'publisher', 'publication_year', 'description'] as $field) {
            $page->assertSee('data-suggest-field="' . $field . '"', false);
        }
        // a guest sees the page too, with the sign-in dialog behind the buttons
        auth()->logout();
        $this->get(route('sources.show', $this->source->code))->assertOk()->assertSee('data-suggest-field="compiler"', false);
    }

    public function test_a_field_suggestion_on_a_source_is_filed_and_approved_onto_it()
    {
        $this->actingAs($this->researcher)->postJson('/suggestions', [
            'type' => 'source', 'id' => $this->source->id, 'kind' => 'field', 'field_name' => 'compiler',
            'suggested_value' => 'سید احمد حسینی اشکوری', 'rationale_citation' => 'صفحه‌ی عنوان جلد یکم',
        ])->assertOk();
        $s = FieldSuggestion::where('suggestable_type', Source::class)->where('suggestable_id', $this->source->id)->sole();
        $this->assertSame(['field', 'compiler', null], [$s->kind, $s->field_name, $s->current_value]);

        // not a field of a source
        $this->actingAs($this->researcher)->postJson('/suggestions', [
            'type' => 'source', 'id' => $this->source->id, 'kind' => 'field', 'field_name' => 'code',
            'suggested_value' => 'x', 'rationale_citation' => 'منبع آزمایشی',
        ])->assertStatus(422)->assertJsonValidationErrors('field_name');

        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee(route('sources.show', $this->source->code), false);
        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/approve", ['applied_value' => $s->suggested_value, 'revision_category' => 'scholarly_correction'])
            ->assertRedirect('/admin/suggestions');
        $this->assertSame('سید احمد حسینی اشکوری', $this->source->fresh()->compiler);
        $this->assertSame('approved', $s->fresh()->status);
        $this->get(route('sources.show', $this->source->code))->assertSee('سید احمد حسینی اشکوری');
    }

    public function test_a_note_on_a_source_is_filed()
    {
        $this->actingAs($this->researcher)->postJson('/suggestions', [
            'type' => 'source', 'id' => $this->source->id, 'kind' => 'note', 'field_name' => 'link',
            'note' => ['title' => 'نسخه‌ی برخط فهرست', 'url' => 'https://example.org/catalogue'],
        ])->assertOk();
        $this->assertSame('note', FieldSuggestion::where('suggestable_type', Source::class)->sole()->kind);
    }

    public function test_a_suggestion_filed_on_the_library_converts_to_the_source_by_its_link()
    {
        // as #106: the compiler of the library's catalogue, sent as the library's description
        $s = FieldSuggestion::create(['suggestable_type' => Library::class, 'suggestable_id' => $this->library->id, 'kind' => 'field', 'field_name' => 'description',
            'suggested_value' => 'نام فهرستنگار سید احمد اشکوری است', 'user_id' => $this->researcher->id, 'guest_name' => $this->researcher->name, 'status' => 'pending']);

        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('تبدیل به پیشنهاد دیگر');
        $this->actingAs($this->admin)->getJson("/admin/suggestions/{$s->id}/convert-fields?type=source&ref=" . urlencode('https://fahares.net/sources/' . $this->source->code))
            ->assertOk()->assertJsonPath('record.id', $this->source->id)->assertJsonFragment(['name' => 'compiler']);

        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/convert", [
            'target_type' => 'source', 'target_ref' => 'https://fahares.net/sources/' . $this->source->code . '?volume=1',
            'kind' => 'field', 'field_name' => 'compiler', 'suggested_value' => 'سید احمد حسینی اشکوری', 'rationale_citation' => 'پیشنهاد #106', 'close_original' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $derived = FieldSuggestion::findOrFail(last($s->fresh()->resolution['converted_to']));
        $this->assertSame([Source::class, $this->source->id, 'compiler', $this->researcher->id],
            [$derived->suggestable_type, $derived->suggestable_id, $derived->field_name, $derived->user_id]);
        $this->assertSame('converted', $s->fresh()->status);

        // a bare code or id finds it too; an unknown code does not
        foreach ([$this->source->code, (string) $this->source->id, '#' . $this->source->id] as $ref) {
            $this->assertSame($this->source->id, \App\Http\Controllers\Admin\SuggestionController::recordId('source', $ref), $ref);
        }
        $this->assertNull(\App\Http\Controllers\Admin\SuggestionController::recordId('source', 'https://fahares.net/sources/no-such-code'));
    }
}
