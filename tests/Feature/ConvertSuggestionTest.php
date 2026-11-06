<?php

namespace Tests\Feature;

use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use App\Suggestions\NoteTypes;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The reviewer's «تبدیل به پیشنهاد دیگر» covers every kind of suggestion the registry defines (backlog item 68):
 * each field of each record type with the record's dynamic address fields, each research note, each relation with
 * its options, a general report and site feedback. A kind added to the registry and left out of the form fails here.
 */
class ConvertSuggestionTest extends TestCase
{
    protected User $admin;

    protected User $researcher;

    /** @var array<string, \Illuminate\Database\Eloquent\Model> one record per type, and a second one for relations */
    protected array $records = [];

    protected array $others = [];

    protected SourceEntry $entry;

    /** The target of a source's cataloger relations (step 14.58) */
    protected \App\Models\Cataloger $cataloger;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Artisan::call('sources:backfill', ['--only' => ['registry']]);
        $this->admin = User::create(['name' => 'داور تبدیل', 'email' => uniqid('cv-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->researcher = User::create(['name' => 'پژوهشگر', 'email' => uniqid('cv-') . '@example.test', 'password' => 'password123', 'role' => 'user']);

        $library = Library::create(['name' => 'کتابخانه‌ی تبدیل', 'city' => 'قم', 'slug' => 'cv-lib-' . uniqid()]);
        $otherLibrary = Library::create(['name' => 'کتابخانه‌ی دیگر', 'city' => 'قم', 'slug' => 'cv-lib-' . uniqid()]);
        // A work citing a page of al-Dharia has a bibliography_page_* field
        $work = Work::create(['primary_title' => 'اثر تبدیل', 'clean_title' => 'اثر تبدیل', 'slug' => 'cv-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1, 'bibliography' => ['الذریعه 101/25']]);
        $otherWork = Work::create(['primary_title' => 'اثر دیگر', 'clean_title' => 'اثر دیگر', 'slug' => 'cv-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 2]);
        $manuscript = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'library' => $library->name, 'shelfmark' => '12', 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1]);
        $otherManuscript = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'library' => $library->name, 'shelfmark' => '13', 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 2]);
        $person = Person::create(['name' => 'شخص تبدیل', 'normalized_name' => 'شخص تبدیل', 'slug' => 'cv-p-' . uniqid()]);
        $otherPerson = Person::create(['name' => 'شخص دیگر', 'normalized_name' => 'شخص دیگر', 'slug' => 'cv-p-' . uniqid()]);

        // The copy's entry in a library catalogue gives it a source_entry_N field (and catalog_reference_N, its name before step 13.3)
        $source = Source::create(['code' => 'cv-edition-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست کتابخانه‌ی تبدیل', 'library_id' => $library->id]);
        $edition = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 2, 'edition_label' => '۱۳۸۸']);
        $this->entry = SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $manuscript->id, 'source_id' => $source->id, 'source_volume_id' => $edition->id,
            'volume_number' => 2, 'page_start' => 5, 'origin' => SourceEntry::ORIGIN_MATCH, 'legacy_type' => 'catalog_reference', 'legacy_id' => 987654]);

        // A source's own page takes field suggestions and notes, with no relations (step 14.52)
        $this->records = ['manuscript' => $manuscript, 'work' => $work, 'person' => $person, 'library' => $library, 'source' => $source];
        $this->cataloger = \App\Models\Cataloger::create(['name' => 'فهرست‌نگار تبدیل', 'slug' => 'cv-cat-' . uniqid()]);
        $this->others = ['manuscript' => $otherManuscript, 'work' => $otherWork, 'person' => $otherPerson, 'library' => $otherLibrary];
    }

    /** A misfiled note on the record, the suggestion the reviewer converts */
    protected function original(?string $type = 'manuscript'): FieldSuggestion
    {
        $record = $type ? $this->records[$type] : null;

        return FieldSuggestion::create([
            'suggestable_type' => $record ? $record::class : null, 'suggestable_id' => $record?->id,
            'kind' => $record ? 'general' : 'feedback', 'field_name' => $record ? null : 'data',
            'suggested_value' => 'متن پژوهشگر', 'user_id' => $this->researcher->id, 'guest_name' => $this->researcher->name, 'status' => 'pending',
        ]);
    }

    protected function convert(FieldSuggestion $s, array $data)
    {
        return $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/convert", $data + ['rationale_citation' => 'یادداشت پژوهشگر']);
    }

    protected function derived(FieldSuggestion $s): FieldSuggestion
    {
        return FieldSuggestion::findOrFail(last($s->fresh()->resolution['converted_to']));
    }

    /** A value each input type accepts */
    protected static function valueFor(array $field): string
    {
        return match ($field['input']) {
            'catalog_page' => 'ج 2، ص 7',
            'boolean' => 'بله',
            'integer' => '5',
            'hijri_date' => '۱۰۹۵ق',
            default => 'مقدار تازه',
        };
    }

    public function test_the_form_offers_every_kind_field_note_and_relation_of_the_registry()
    {
        foreach ($this->records as $type => $record) {
            $page = $this->actingAs($this->admin)->get('/admin/suggestions/' . $this->original($type)->id)->assertOk()->assertSee('تبدیل به پیشنهاد دیگر');
            foreach (SuggestionRegistry::KINDS as $kind) {
                $this->assertArrayHasKey($kind, SuggestionRegistry::KIND_LABELS, "kind {$kind} has no label in the convert form");
            }
            // The setup the form reads, with the record's own dynamic fields
            foreach (array_keys(SuggestionRegistry::fieldChoices($type, $record)) as $name) {
                $page->assertSee($name, false);
            }
            foreach (array_keys(SuggestionRegistry::RELATIONS[$type] ?? []) as $key) {
                $page->assertSee($key, false);
            }
            foreach (array_keys(NoteTypes::forRecord($type)) as $key) {
                $page->assertSee($key, false);
            }
        }
        $this->assertArrayHasKey('source_entry_' . $this->entry->id, SuggestionRegistry::fieldChoices('manuscript', $this->records['manuscript']));
        $this->assertArrayHasKey('bibliography_page_dharia_25_101', SuggestionRegistry::fieldChoices('work', $this->records['work']));
    }

    public function test_every_field_of_every_record_type_converts_its_dynamic_fields_included()
    {
        foreach ($this->records as $type => $record) {
            $fields = SuggestionRegistry::fieldChoices($type, $record);
            if ($type === 'manuscript') {
                // The name a source entry's field had before step 13.3
                $fields['catalog_reference_987654'] = ['name' => 'catalog_reference_987654', 'input' => 'catalog_page'];
            }
            foreach ($fields as $name => $field) {
                $s = $this->original($type);
                $this->convert($s, ['target_type' => $type, 'kind' => 'field', 'field_name' => $name, 'suggested_value' => self::valueFor($field)])
                    ->assertSessionHasNoErrors()->assertRedirect();
                $derived = $this->derived($s);
                $this->assertSame(['field', $name, $record->id, $this->researcher->id], [$derived->kind, $derived->field_name, $derived->suggestable_id, $derived->user_id], "{$type}.{$name}");
            }
        }

        // An address is stored in its standard form, and «نیست» only where the source may lack the record
        $ms = $this->records['manuscript'];
        $s = $this->original();
        $this->convert($s, ['target_type' => 'manuscript', 'kind' => 'field', 'field_name' => 'source_entry_' . $this->entry->id, 'suggested_value' => 'ج 3، ص 41'])->assertSessionHasNoErrors();
        $this->assertSame(['ج ۳، ص ۴۱', 'ج ۲، ص ۵'], [$this->derived($s)->suggested_value, $this->derived($s)->current_value]);
        $this->convert($this->original(), ['target_type' => 'manuscript', 'kind' => 'field', 'field_name' => 'source_entry_' . $this->entry->id, 'suggested_value' => 'نیست'])->assertSessionHasNoErrors();
        $this->convert($this->original(), ['target_type' => 'manuscript', 'kind' => 'field', 'field_name' => 'fankha_page', 'suggested_value' => 'نیست'])->assertSessionHasErrors('suggested_value');
        // Another copy's entry is not this copy's field
        $this->convert($this->original('manuscript'), ['target_type' => 'manuscript', 'target_ref' => (string) $this->others['manuscript']->id, 'kind' => 'field', 'field_name' => 'source_entry_' . $this->entry->id, 'suggested_value' => 'ج 3، ص 41'])
            ->assertSessionHasErrors('field_name');

        // A scribe picked from the people list is linked on approval
        $s = $this->original();
        $person = $this->records['person'];
        $this->convert($s, ['target_type' => 'manuscript', 'kind' => 'field', 'field_name' => 'scribe_name', 'suggested_value' => $person->name, 'person_id' => $person->id])->assertSessionHasNoErrors();
        $this->assertSame($person->id, $this->derived($s)->payload['person_id']);
        $this->assertSame($ms->id, $this->derived($s)->suggestable_id);
    }

    public function test_every_relation_of_every_record_type_converts_with_its_options()
    {
        foreach (SuggestionRegistry::RELATIONS as $type => $relations) {
            $choices = collect(SuggestionRegistry::relationChoices($type))->keyBy('key');
            $this->assertSame(array_keys($relations), $choices->keys()->all(), "the form lists every relation of {$type}");
            foreach ($relations as $key => $relation) {
                $choice = $choices[$key];
                $data = ['target_type' => $type, 'kind' => 'relation', 'field_name' => $key];
                if ($relation['target']) {
                    $data['relation_target'] = (string) ($relation['target'] === 'cataloger' ? $this->cataloger
                        : ($relation['target'] === $type ? $this->others[$type] : $this->records[$relation['target']]))->id;
                    if ($key === 'relocated') {
                        $data['relation_target'] = (string) $this->others['library']->id;
                    }
                }
                if ($choice['options']) {
                    $data['role'] = array_key_first($choice['options']);
                }
                if (! empty($relation['needs_text'])) {
                    $data['suggested_value'] = 'نام یا بخش تازه';
                }
                $s = $this->original($type);
                $this->convert($s, $data)->assertSessionHasNoErrors()->assertRedirect();
                $derived = $this->derived($s);
                $this->assertSame(['relation', $key], [$derived->kind, $derived->field_name], "{$type}.{$key}");
                if ($choice['options']) {
                    $this->assertSame($data['role'], $derived->payload['role']);
                }
            }
        }
    }

    public function test_every_note_type_converts()
    {
        foreach ($this->records as $type => $record) {
            foreach (NoteTypes::forRecord($type) as $key => $note) {
                $values = [];
                foreach (array_unique(array_merge($note['required'], ! empty($note['files']) ? ['url'] : [])) as $f) {
                    $values[$f] = $f === 'url' ? 'https://example.org/page' : 'نوشته';
                }
                $s = $this->original($type);
                $this->convert($s, ['target_type' => $type, 'kind' => 'note', 'field_name' => $key, 'note' => $values])->assertSessionHasNoErrors();
                $this->assertSame(['note', $key], [$this->derived($s)->kind, $this->derived($s)->field_name], "{$type}.{$key}");
            }
        }
    }

    public function test_a_suggestion_converts_into_site_feedback_and_feedback_into_a_record_suggestion()
    {
        $s = $this->original();
        $this->convert($s, ['kind' => 'feedback', 'suggested_value' => 'جستجو کار نمی‌کند'])->assertSessionHasErrors('field_name');
        $this->convert($s, ['kind' => 'feedback', 'field_name' => 'bug', 'suggested_value' => 'جستجو کار نمی‌کند', 'close_original' => 1])->assertSessionHasNoErrors();
        $derived = $this->derived($s);
        $this->assertSame(['feedback', 'bug', null, $this->researcher->id], [$derived->kind, $derived->field_name, $derived->suggestable_type, $derived->user_id]);
        $this->assertSame('converted', $s->fresh()->status);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$derived->id}")->assertOk();

        // and back: feedback names no record, so the reviewer names one
        $feedback = $this->original(null);
        $this->convert($feedback, ['target_type' => 'work', 'kind' => 'general', 'suggested_value' => 'x'])->assertSessionHasErrors('target_ref');
        $this->convert($feedback, ['target_type' => 'work', 'target_ref' => 'fahares.net/w/' . $this->records['work']->id, 'kind' => 'field', 'field_name' => 'bibliography_page_dharia_25_101', 'suggested_value' => 'ج ۲۵، ص ۱۰۲'])->assertSessionHasNoErrors();
        $this->assertSame(['ج ۲۵، ص ۱۰۲', $this->records['work']->id], [$this->derived($feedback)->suggested_value, $this->derived($feedback)->suggestable_id]);
    }

    public function test_the_fields_of_another_named_record_come_with_its_dynamic_fields()
    {
        $s = $this->original('work');
        $ms = $this->records['manuscript'];
        $this->actingAs($this->admin)->getJson("/admin/suggestions/{$s->id}/convert-fields?type=manuscript&ref=" . urlencode("https://fahares.net/m/{$ms->id}"))
            ->assertOk()->assertJsonPath('record.id', $ms->id)
            ->assertJsonFragment(['name' => 'source_entry_' . $this->entry->id, 'input' => 'catalog_page', 'canBeAbsent' => true])
            ->assertJsonFragment(['name' => 'fankha_page', 'withRow' => true]);
        $this->actingAs($this->admin)->getJson("/admin/suggestions/{$s->id}/convert-fields?type=manuscript&ref=nothing")
            ->assertOk()->assertJsonPath('record', null);
        $this->actingAs($this->researcher)->getJson("/admin/suggestions/{$s->id}/convert-fields?type=manuscript&ref=1")->assertForbidden();
    }

    /**
     * Step 14.60: a note that an «autograph» copy is not in the author's hand converts into a suggestion to empty the
     * scribe. Its current value names the autograph and the linked person, and approving it with «خالی کردن فیلد»
     * takes the name, the link and the autograph mark away.
     */
    public function test_a_suggestion_to_empty_the_scribe_of_an_autograph_copy_converts_and_applies()
    {
        $ms = $this->records['manuscript'];
        $author = $this->records['person'];
        $ms->update(['scribe_id' => $author->id, 'scribe_name' => null, 'is_autograph' => true]);
        $s = $this->original();

        // an empty value without the mark is still refused
        $this->convert($s, ['kind' => 'field', 'target_type' => 'manuscript', 'field_name' => 'scribe_name', 'suggested_value' => ''])
            ->assertSessionHasErrors('suggested_value');

        $this->convert($s, ['kind' => 'field', 'target_type' => 'manuscript', 'field_name' => 'scribe_name', 'suggested_value' => '', 'clear' => '1'])
            ->assertSessionHasNoErrors();
        $derived = $this->derived($s);
        $this->assertSame('دستخط مؤلف (شخص تبدیل)', $derived->current_value);
        $this->assertSame('', $derived->suggested_value);
        $this->assertTrue($derived->payload['clear']);

        $this->actingAs($this->admin)->get("/admin/suggestions/{$derived->id}")->assertOk()
            ->assertSee('دستخط مؤلف (شخص تبدیل)')->assertSee(SuggestionRegistry::CLEAR_LABEL)
            ->assertSee('value="clear" checked', false);

        $this->actingAs($this->admin)->post("/admin/suggestions/{$derived->id}/approve", ['person_pick' => 'clear', 'applied_value' => 'ignored', 'outcome' => 'adopt'])
            ->assertSessionHasNoErrors();
        $ms->refresh();
        $this->assertNull($ms->scribe_name);
        $this->assertNull($ms->scribe_id);
        $this->assertFalse($ms->is_autograph);
        $this->assertSame(0, $ms->scribeEntries()->count());
        $this->assertNull($derived->fresh()->applied_value);
        $this->assertTrue($derived->fresh()->resolution['cleared']);

        // taking the decision back restores the scribe, its link and the mark
        $this->actingAs($this->admin)->post("/admin/suggestions/{$derived->id}/reopen")->assertSessionHas('success');
        $ms->refresh();
        $this->assertSame($author->id, $ms->scribe_id);
        $this->assertTrue($ms->is_autograph);
        $this->assertSame(1, $ms->scribeEntries()->count());
    }

    /** A yes/no flag, or a field that is already empty, cannot be suggested empty */
    public function test_a_flag_or_an_empty_field_cannot_be_suggested_empty()
    {
        $s = $this->original();
        $this->convert($s, ['kind' => 'field', 'target_type' => 'manuscript', 'field_name' => 'is_autograph', 'clear' => '1'])
            ->assertSessionHasErrors('suggested_value');
        $this->convert($s, ['kind' => 'field', 'target_type' => 'manuscript', 'field_name' => 'copy_place', 'clear' => '1'])
            ->assertSessionHasErrors('suggested_value');
        $this->assertNull($s->fresh()->resolution);
    }

    /** A suggestion saved before step 14.60 without the value then on the record shows the value now */
    public function test_a_field_suggestion_without_a_stored_current_value_shows_the_live_one()
    {
        $ms = $this->records['manuscript'];
        $ms->update(['scribe_id' => $this->records['person']->id, 'scribe_name' => null, 'is_autograph' => true]);
        $s = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $ms->id, 'kind' => 'field', 'field_name' => 'scribe_name',
            'suggested_value' => '--', 'current_value' => null, 'user_id' => $this->researcher->id, 'status' => 'pending']);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()
            ->assertSee('دستخط مؤلف (شخص تبدیل)')->assertDontSee('— فیلد خالی است —');
    }
}
