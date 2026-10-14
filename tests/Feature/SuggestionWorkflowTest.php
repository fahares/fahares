<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\SourceCitation;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\ScholarlyAnnotation;
use App\Models\User;
use App\Models\Work;
use App\Support\HijriYear;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuggestionWorkflowTest extends TestCase
{
    protected User $admin;
    protected array $works = [];
    protected array $peopleIds = [];
    protected array $libraryIds = [];
    protected array $removedManuscripts = [];
    protected string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'آزمونک' . random_int(10000, 99999);
        $this->admin = User::firstOrCreate(
            ['email' => 'test-admin@fahares.net'],
            ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']
        );
        $this->admin->forceFill(['role' => 'admin'])->save();
    }

    protected function tearDown(): void
    {
        $workIds = array_map(fn ($w) => $w->id, $this->works);
        $msIds = Manuscript::whereIn('work_id', $workIds)->pluck('id');
        $this->peopleIds = array_merge($this->peopleIds, Person::where('name', 'like', "%{$this->tag}%")->pluck('id')->all());
        // Manuscripts removed by a merge are already gone; their redirects still need cleaning
        $msIds = $msIds->merge($this->removedManuscripts);

        $targets = [Manuscript::class => $msIds->all(), Work::class => $workIds, Person::class => $this->peopleIds, Library::class => $this->libraryIds];
        foreach ($targets as $class => $ids) {
            $suggestionIds = FieldSuggestion::where('suggestable_type', $class)->whereIn('suggestable_id', $ids)->pluck('id');
            ScholarlyAnnotation::whereIn('suggestion_id', $suggestionIds)->delete();
            FieldSuggestion::whereIn('id', $suggestionIds)->delete();
            AuditLog::where('auditable_type', $class)->whereIn('auditable_id', $ids)->delete();
        }
        Library::whereIn('id', $this->libraryIds)->delete();
        foreach (['manuscripts' => $msIds->all(), 'works' => $workIds, 'people' => $this->peopleIds] as $entity => $ids) {
            EntityRedirect::where('entity_type', $entity)->where(fn ($q) => $q->whereIn('source_id', $ids)->orWhereIn('target_id', $ids))->delete();
        }
        SourceCitation::where('citable_type', Work::class)->whereIn('citable_id', $workIds)->delete();
        SourceCitation::where('citable_type', Manuscript::class)->whereIn('citable_id', $msIds)->delete();
        Work::whereIn('id', $workIds)->delete();
        Person::whereIn('id', $this->peopleIds)->delete();

        parent::tearDown();
    }

    protected function person(string $name): Person
    {
        $p = Person::create(['name' => "{$name} {$this->tag}", 'normalized_name' => $name, 'slug' => 'test-suggest-' . uniqid(), 'is_scribe' => true]);
        $this->peopleIds[] = $p->id;

        return $p;
    }

    protected function manuscript(array $attributes = []): Manuscript
    {
        $work = $this->works[] = Work::create([
            'primary_title' => 'اثر آزمایشی پیشنهاد ' . uniqid(),
            'clean_title' => 'اثر آزمایشی پیشنهاد',
            'slug' => 'test-suggest-work-' . uniqid(),
            'volume_number' => 1,
            'page_start' => 1,
        ]);

        return Manuscript::create($attributes + [
            'work_id' => $work->id,
            'sequence_number' => 1,
            'volume_number' => 1,
            'page_start' => 1,
        ]);
    }

    protected function work(array $attributes = []): Work
    {
        return $this->works[] = Work::create($attributes + [
            'primary_title' => 'اثر آزمایشی پیشنهاد ' . uniqid(),
            'clean_title' => 'اثر آزمایشی پیشنهاد',
            'slug' => 'test-suggest-work-' . uniqid(),
            'volume_number' => 1,
            'page_start' => 1,
        ]);
    }

    protected function suggest($record, array $data)
    {
        $type = \App\Suggestions\SuggestionRegistry::typeKey($record::class);

        return $this->postJson('/suggestions', $data + ['type' => $type, 'id' => $record->id, 'kind' => 'field']);
    }

    protected function pendingFor($record): FieldSuggestion
    {
        return FieldSuggestion::where('suggestable_type', $record::class)->where('suggestable_id', $record->id)->where('status', 'pending')->latest('id')->firstOrFail();
    }

    protected function approve(FieldSuggestion $s, array $data = [])
    {
        return $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/approve", $data + [
            'applied_value' => $s->suggested_value,
            'revision_category' => 'scholarly_correction',
        ]);
    }

    public function test_hijri_year_parser()
    {
        $cases = [
            'یک‌شنبه ۲۳ ربیع الاول ۱۲۳۶ق' => 1236,
            '1095 هـ.ق' => 1095,
            '1034 تا 1052 ق' => 1034,
            'قرن 13' => null,
            'سده‌ی ۱۳' => null,
            '1820م' => null,
            '۱۳۵۰ش' => null,
            '1350ش / 1390ق' => 1390,
            '27 جمادی الثانی 127=1207ق' => 1207,
            'تا برگ 162 از قرن 11 و بقیه ترمیمی سال 1277' => 1277,
        ];
        foreach ($cases as $raw => $year) {
            $this->assertSame($year, HijriYear::parse($raw), $raw);
        }
    }

    public function test_saving_date_text_keeps_year_in_step()
    {
        $m = $this->manuscript(['copy_date_raw' => 'رجب ۹۸۰ق']);
        $this->assertSame(980, $m->fresh()->copy_date_hijri_year);

        $m->update(['copy_date_raw' => 'قرن ۱۱']);
        $this->assertNull($m->fresh()->copy_date_hijri_year);

        // An explicit year set alongside the text wins
        $m->update(['copy_date_raw' => 'بی‌تاریخ، حدود ۱۱۰۰', 'copy_date_hijri_year' => 1105]);
        $this->assertSame(1105, $m->fresh()->copy_date_hijri_year);
    }

    public function test_field_suggestion_is_validated_against_the_registry()
    {
        $m = $this->manuscript(['dimensions' => '۱۰×۱۵']);

        $this->suggest($m, ['field_name' => 'work_id', 'suggested_value' => '1', 'rationale_citation' => 'منبع آزمایشی'])
            ->assertStatus(422)->assertJsonValidationErrors('field_name');
        $this->suggest($m, ['field_name' => 'dimensions', 'suggested_value' => '۱۲×۱۸', 'rationale_citation' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('rationale_citation');
        $this->suggest($m, ['field_name' => 'dimensions', 'suggested_value' => '۱۲×۱۸', 'rationale_citation' => 'منبع آزمایشی', 'website' => 'spam'])
            ->assertStatus(422);

        $this->suggest($m, ['field_name' => 'dimensions', 'suggested_value' => '۱۲×۱۸', 'rationale_citation' => 'منبع آزمایشی', 'current_value' => 'جعلی'])
            ->assertOk();

        $s = FieldSuggestion::where('suggestable_id', $m->id)->sole();
        $this->assertSame('field', $s->kind);
        $this->assertSame('۱۰×۱۵', $s->current_value, 'current value is read from the record, not the request');
    }

    public function test_general_and_legacy_suggestions_need_no_field()
    {
        $m = $this->manuscript();

        $this->postJson('/suggestions', ['type' => 'manuscript', 'id' => $m->id, 'kind' => 'general', 'suggested_value' => 'این نسخه از اثر دیگری است'])
            ->assertOk();
        // A page cached before the per-field desk posts the class name and the old "other" field
        $this->post('/suggestions', [
            'suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'field_name' => 'other',
            'suggested_value' => 'نکته‌ی دیگر', 'rationale_citation' => 'منبع آزمایشی',
        ])->assertRedirect();

        $kinds = FieldSuggestion::where('suggestable_id', $m->id)->where('suggestable_type', Manuscript::class)->pluck('kind', 'suggested_value');
        $this->assertSame(['این نسخه از اثر دیگری است' => 'general', 'نکته‌ی دیگر' => 'general'], $kinds->all());
    }

    public function test_approving_a_date_writes_the_numeric_year()
    {
        $m = $this->manuscript(['is_bita' => true]);
        $this->suggest($m, ['field_name' => 'copy_date_raw', 'suggested_value' => '۱۴ رجب ۱۲۳۹ق', 'rationale_citation' => 'ترقیمه‌ی نسخه'])->assertOk();
        $s = FieldSuggestion::where('suggestable_id', $m->id)->sole();

        $this->get("/admin/suggestions/{$s->id}")->assertRedirect('/login');
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('value="1239"', false);

        $this->approve($s, ['hijri_year' => 1239])->assertRedirect('/admin/suggestions');

        $m->refresh();
        $this->assertSame('۱۴ رجب ۱۲۳۹ق', $m->copy_date_raw);
        $this->assertSame(1239, $m->copy_date_hijri_year);
        $this->assertFalse($m->is_bita);
        $this->assertSame(1239, $s->fresh()->resolution['hijri_year']);
    }

    public function test_approving_a_scribe_links_an_existing_person()
    {
        $old = $this->person('کاتب پیشین');
        $new = $this->person('محمد کاتب');
        $m = $this->manuscript(['scribe_id' => $old->id, 'scribe_name' => $old->name]);
        $old->recountContributions();

        $this->suggest($m, ['field_name' => 'scribe_name', 'suggested_value' => $new->name, 'rationale_citation' => 'ترقیمه', 'person_id' => $new->id])->assertOk();
        $s = FieldSuggestion::where('suggestable_id', $m->id)->sole();
        $this->assertSame($new->id, $s->payload['person_id']);

        $this->approve($s, ['person_pick' => 'p:' . $new->id])->assertRedirect('/admin/suggestions');

        $m->refresh();
        $this->assertSame($new->id, $m->scribe_id);
        $this->assertSame([$new->id], DB::table('manuscript_scribe')->where('manuscript_id', $m->id)->pluck('person_id')->all());
        $this->assertSame(1, $new->fresh()->manuscripts_count);
        $this->assertSame(0, $old->fresh()->manuscripts_count);

        // Rolling back the approval restores the old link and reopens the suggestion
        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $m->id)->where('action', 'verify')->sole();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame($old->id, $m->fresh()->scribe_id);
        $this->assertSame(1, $old->fresh()->manuscripts_count);
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_approving_a_scribe_can_create_a_person_or_store_text_only()
    {
        $m = $this->manuscript();
        $name = "علی بن حسن {$this->tag}";
        $this->suggest($m, ['field_name' => 'scribe_name', 'suggested_value' => $name, 'rationale_citation' => 'ترقیمه'])->assertOk();
        $this->approve(FieldSuggestion::where('suggestable_id', $m->id)->sole(), ['person_pick' => 'create']);

        $created = Person::where('name', $name)->sole();
        $this->assertTrue($created->is_scribe);
        $this->assertSame(1, $created->manuscripts_count);
        $this->assertSame($created->id, $m->fresh()->scribe_id);

        $other = $this->manuscript();
        $this->suggest($other, ['field_name' => 'scribe_name', 'suggested_value' => 'کاتبی ناشناس', 'rationale_citation' => 'ترقیمه'])->assertOk();
        $this->approve(FieldSuggestion::where('suggestable_id', $other->id)->sole(), ['person_pick' => 'text']);
        $other->refresh();
        $this->assertSame('کاتبی ناشناس', $other->scribe_name);
        $this->assertNull($other->scribe_id);
    }

    public function test_metadata_fields_and_general_reports()
    {
        $m = $this->manuscript(['metadata' => ['incipits' => [['label' => null, 'text' => 'آغاز نادرست']]]]);

        $this->suggest($m, ['field_name' => 'incipit_text', 'suggested_value' => 'بسم الله الرحمن الرحیم', 'rationale_citation' => 'برگ نخست'])->assertOk();
        $this->suggest($m, ['field_name' => 'ownership_and_seals', 'suggested_value' => "مهر الف\nتملک ب", 'rationale_citation' => 'برگ نخست'])->assertOk();
        $this->suggest($m, ['field_name' => 'folios', 'suggested_value' => '۱۲۰', 'rationale_citation' => 'شمارش برگ‌ها'])->assertOk();
        $this->postJson('/suggestions', ['type' => 'manuscript', 'id' => $m->id, 'kind' => 'general', 'suggested_value' => 'رکورد تکراری است'])->assertOk();

        foreach (FieldSuggestion::where('suggestable_id', $m->id)->where('suggestable_type', Manuscript::class)->get() as $s) {
            $this->approve($s)->assertRedirect('/admin/suggestions');
        }

        $m->refresh();
        $this->assertSame([['label' => null, 'text' => 'بسم الله الرحمن الرحیم']], $m->metadata['incipits']);
        $this->assertSame(['مهر الف', 'تملک ب'], $m->ownership_and_seals_list);
        $this->assertSame(120, $m->folios);

        $general = FieldSuggestion::where('suggestable_id', $m->id)->where('kind', 'general')->sole();
        $this->assertSame('approved', $general->status);
        $this->assertFalse($general->resolution['applied']);
        $this->assertNull(ScholarlyAnnotation::where('suggestion_id', $general->id)->first());
    }

    public function test_invalid_integer_is_rejected_without_changes()
    {
        $m = $this->manuscript(['folios' => 50]);
        $this->suggest($m, ['field_name' => 'folios', 'suggested_value' => 'حدود صد', 'rationale_citation' => 'شمارش'])->assertOk();
        $s = FieldSuggestion::where('suggestable_id', $m->id)->sole();

        $this->approve($s)->assertSessionHasErrors('applied_value');
        $this->assertSame(50, $m->fresh()->folios);
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_manuscript_page_renders_the_suggestion_desk()
    {
        $m = $this->manuscript();
        $this->get(route('manuscripts.show', $m))
            ->assertOk()
            ->assertSee('suggestDesk(', false)
            ->assertSee('پیشنهاد اصلاح این فیلد');
    }

    public function test_work_fields_link_author_and_keep_derived_columns()
    {
        $author = $this->person('مؤلف آزمایشی');
        $work = $this->work([
            'alternative_titles' => [['title' => 'عنوان کهن', 'transliteration' => 'ʿunwān-i kuhan']],
        ]);

        $this->suggest($work, ['field_name' => 'author_name', 'suggested_value' => $author->name, 'rationale_citation' => 'مقدمه‌ی اثر', 'person_id' => $author->id])->assertOk();
        $this->approve($this->pendingFor($work), ['person_pick' => 'p:' . $author->id])->assertRedirect('/admin/suggestions');

        $this->suggest($work, ['field_name' => 'composition_date_raw', 'suggested_value' => 'رمضان ۸۹۵ق', 'rationale_citation' => 'انجامه‌ی مؤلف'])->assertOk();
        $this->approve($this->pendingFor($work), ['hijri_year' => 895]);

        $this->suggest($work, ['field_name' => 'alternative_titles', 'suggested_value' => "عنوان کهن\nعنوان تازه", 'rationale_citation' => 'فهرست ملی'])->assertOk();
        $this->approve($this->pendingFor($work));

        $this->suggest($work, ['field_name' => 'primary_title', 'suggested_value' => 'عنوان درست', 'rationale_citation' => 'سرلوح نسخه'])->assertOk();
        $this->approve($this->pendingFor($work));

        $work->refresh();
        $this->assertSame($author->id, $work->author_id);
        $this->assertSame([$author->id], DB::table('work_author')->where('work_id', $work->id)->pluck('person_id')->all());
        $this->assertSame(1, $author->fresh()->works_count);
        $this->assertSame(895, $work->composition_year_hijri);
        $this->assertSame([
            ['title' => 'عنوان کهن', 'transliteration' => 'ʿunwān-i kuhan'],
            ['title' => 'عنوان تازه'],
        ], $work->alternative_titles);
        $this->assertSame('عنوان درست', $work->primary_title);
        $this->assertSame('عنوان درست', $work->clean_title);

        // A title cannot be emptied
        $this->suggest($work, ['field_name' => 'primary_title', 'suggested_value' => 'x', 'rationale_citation' => 'آزمون خالی'])->assertOk();
        $this->approve($this->pendingFor($work), ['applied_value' => '', 'previous_standing' => 'superseded', 'public_note' => 'آزمون'])->assertSessionHasErrors('applied_value');
        $this->assertSame('عنوان درست', $work->fresh()->primary_title);
    }

    public function test_person_fields_update_derived_columns_and_respect_ranges()
    {
        $person = $this->person('شخص آزمایشی');

        $this->suggest($person, ['field_name' => 'death_year_hijri', 'suggested_value' => '۶۷۲', 'rationale_citation' => 'تذکره'])->assertOk();
        $this->approve($this->pendingFor($person));
        $this->assertSame(672, $person->fresh()->death_year_hijri);
        $this->assertSame(7, $person->fresh()->century_hijri);

        $this->suggest($person, ['field_name' => 'death_year_gregorian', 'suggested_value' => '20', 'rationale_citation' => 'تذکره'])->assertOk();
        $this->approve($this->pendingFor($person))->assertSessionHasErrors('applied_value');
        $this->assertNull($person->fresh()->death_year_gregorian);

        $newName = "نام درست‌شده {$this->tag}";
        $this->suggest($person, ['field_name' => 'name', 'suggested_value' => $newName, 'rationale_citation' => 'تذکره'])->assertOk();
        $this->approve($this->pendingFor($person));
        $this->assertSame(\App\Helpers\TextNormalizer::normalize($newName), $person->fresh()->normalized_name);

        $this->suggest($person, ['field_name' => 'aliases', 'suggested_value' => "لقب یک\nلقب دو", 'rationale_citation' => 'تذکره'])->assertOk();
        $this->approve($this->pendingFor($person));
        $this->assertSame(['لقب یک', 'لقب دو'], $person->fresh()->aliases);
    }

    public function test_library_fields_and_record_pages()
    {
        $library = Library::create(['city' => 'شهر آزمون', 'name' => 'کتابخانه آزمون ' . $this->tag, 'slug' => 'test-suggest-lib-' . uniqid()]);
        $this->libraryIds[] = $library->id;

        // Name and city are copied onto manuscripts, so they are not open to field suggestions
        $this->suggest($library, ['field_name' => 'name', 'suggested_value' => 'دیگر', 'rationale_citation' => 'تابلوی کتابخانه'])
            ->assertStatus(422)->assertJsonValidationErrors('field_name');

        $this->suggest($library, ['field_name' => 'description', 'suggested_value' => 'کتابخانه‌ای وقفی', 'rationale_citation' => 'وقف‌نامه'])->assertOk();
        $this->approve($this->pendingFor($library));
        $this->assertSame('کتابخانه‌ای وقفی', $library->fresh()->description);

        $work = $this->work();
        $person = $this->person('شخص صفحه');
        foreach ([route('works.show', $work), route('people.show', $person), route('libraries.show', $library)] as $url) {
            $this->get($url)->assertOk()->assertSee('suggestDesk(', false)->assertSee('پیشنهاد اصلاح این فیلد');
        }
    }

    protected function relate($record, string $key, $other, array $extra = [])
    {
        $type = \App\Suggestions\SuggestionRegistry::typeKey($record::class);

        return $this->postJson('/suggestions', $extra + [
            'type' => $type, 'id' => $record->id, 'kind' => 'relation', 'field_name' => $key,
            'target_id' => $other?->id, 'suggested_value' => '', 'rationale_citation' => 'مقایسه‌ی دو فهرست',
        ]);
    }

    public function test_relation_suggestions_are_validated()
    {
        $m = $this->manuscript();
        $work = $this->work();
        $library = Library::create(['city' => 'شهر آزمون', 'name' => 'کتابخانه آزمون ' . $this->tag, 'slug' => 'test-suggest-lib-' . uniqid()]);
        $this->libraryIds[] = $library->id;

        $this->relate($library, 'duplicate', $library)->assertStatus(422)->assertJsonValidationErrors('field_name');
        $this->relate($m, 'move_to_work', null)->assertStatus(422)->assertJsonValidationErrors('target_id');
        $this->relate($m, 'duplicate', $m)->assertStatus(422)->assertJsonValidationErrors('target_id');
        $this->relate($m, 'move_to_work', $work, ['rationale_citation' => ''])->assertStatus(422)->assertJsonValidationErrors('rationale_citation');

        $this->relate($m, 'move_to_work', $work)->assertOk();
        $s = $this->pendingFor($m);
        $this->assertSame(['relation', 'move_to_work', $work->id], [$s->kind, $s->field_name, $s->payload['target_id']]);
        $this->assertSame('این نسخه از اثر دیگری است', $s->suggested_value);
        $this->assertSame('انتقال نسخه به اثر دیگر', $s->field_label);

        // The target picker finds records by pasted link or id
        $this->getJson('/api/suggestions/lookup?type=manuscript&q=' . urlencode("https://fahares.net/m/{$m->id}"))->assertOk()->assertJsonPath('0.id', $m->id);
        $this->getJson('/api/suggestions/lookup?type=work&q=' . urlencode('#' . $work->id))->assertOk()->assertJsonPath('0.id', $work->id);
    }

    public function test_moving_a_manuscript_to_another_work_and_back()
    {
        $m = $this->manuscript();
        $from = $m->work;
        $to = $this->work();
        $from->update(['manuscripts_count' => 1]);

        $this->relate($m, 'move_to_work', $to)->assertOk();
        $s = $this->pendingFor($m);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('انتقال نسخه به اثر دیگر');
        $this->approve($s)->assertRedirect('/admin/suggestions');

        $this->assertSame($to->id, $m->fresh()->work_id);
        $this->assertSame(0, $from->fresh()->manuscripts_count);
        $this->assertSame(1, $to->fresh()->manuscripts_count);
        $this->assertSame($to->id, $s->fresh()->resolution['work_id']);

        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $m->id)->where('action', 'verify')->sole();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame($from->id, $m->fresh()->work_id);
        $this->assertSame(1, $from->fresh()->manuscripts_count);
        $this->assertSame(0, $to->fresh()->manuscripts_count);
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_merging_a_duplicate_manuscript()
    {
        $scribe = $this->person('کاتب نسخه‌ی تکراری');
        $kept = $this->manuscript(['shelfmark' => '۱۲۳']);
        $dup = $this->manuscript(['copy_date_raw' => '۱۰۹۵ق', 'scribe_id' => $scribe->id, 'scribe_name' => $scribe->name]);
        $this->removedManuscripts[] = $dup->id;
        $this->suggest($dup, ['field_name' => 'folios', 'suggested_value' => '۱۰', 'rationale_citation' => 'شمارش'])->assertOk();
        $open = $this->pendingFor($dup);

        $this->relate($dup, 'duplicate', $kept)->assertOk();
        $this->approve($this->pendingFor($dup))->assertRedirect('/admin/suggestions');

        $this->assertNull(Manuscript::find($dup->id));
        $this->assertSame($kept->id, EntityRedirect::resolveTargetId('manuscripts', $dup->id));
        $kept->refresh();
        $this->assertSame('۱۰۹۵ق', $kept->copy_date_raw);
        $this->assertSame(1095, $kept->copy_date_hijri_year);
        $this->assertSame($scribe->id, $kept->scribe_id);
        $this->assertSame(1, $scribe->fresh()->manuscripts_count);
        $this->assertSame($kept->id, $open->fresh()->suggestable_id, 'open suggestions follow the kept record');
        $this->assertSame(0, $dup->work->fresh()->manuscripts_count);

        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $kept->id)->where('action', 'merge')->sole();
        $this->assertSame($dup->id, $log->old_values['source_snapshot']['attributes']['id']);
    }

    public function test_merging_a_duplicate_work_keeping_the_suggesting_record()
    {
        $kept = $this->work(['primary_title' => 'عنوان ماندنی ' . $this->tag]);
        $dup = $this->work(['primary_title' => 'عنوان تکراری ' . $this->tag, 'composition_date_raw' => '۹۰۰ق']);
        $same = $this->manuscript(['work_id' => $kept->id, 'city' => 'قم', 'library' => 'مرعشی', 'shelfmark' => '۵۵']);
        $copy = $this->manuscript(['work_id' => $dup->id, 'city' => 'قم', 'library' => 'مرعشی', 'shelfmark' => '۵۵', 'folios' => 80]);
        $other = $this->manuscript(['work_id' => $dup->id, 'city' => 'قم', 'library' => 'مرعشی', 'shelfmark' => '۵۶']);
        $this->removedManuscripts[] = $copy->id;

        // Suggested on the record that should stay; the reviewer keeps it instead of the target
        $this->relate($kept, 'duplicate', $dup)->assertOk();
        $this->approve($this->pendingFor($kept), ['keep' => 'source'])->assertRedirect('/admin/suggestions');

        $this->assertNull(Work::find($dup->id));
        $this->assertSame($kept->id, EntityRedirect::resolveTargetId('works', $dup->id));
        $this->assertSame($kept->id, $other->fresh()->work_id);
        $this->assertNull(Manuscript::find($copy->id), 'the identical copy is folded into the kept one');
        $this->assertSame(80, $same->fresh()->folios);
        $kept->refresh();
        $this->assertSame(2, $kept->manuscripts_count);
        $this->assertSame(900, $kept->composition_year_hijri);
        $this->assertContains('عنوان تکراری ' . $this->tag, array_column($kept->alternative_titles, 'title'));
    }

    public function test_person_duplicates_close_through_the_merge_tool()
    {
        $a = $this->person('شخص یکم');
        $b = $this->person('شخص دوم');
        $this->relate($a, 'duplicate', $b)->assertOk();
        $s = $this->pendingFor($a);

        $this->approve($s)->assertStatus(422);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('suggestion_id=' . $s->id, false);

        $this->actingAs($this->admin)->post('/admin/people/merge', ['source_id' => $a->id, 'target_id' => $b->id, 'suggestion_id' => $s->id])->assertRedirect();
        $s->refresh();
        $this->assertSame('approved', $s->status);
        $this->assertSame(['applied' => true, 'kept_id' => $b->id, 'removed_id' => $a->id], $s->resolution);

        $log = AuditLog::where('auditable_type', Person::class)->where('auditable_id', $b->id)->where('action', 'merge')->sole();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame('pending', $s->fresh()->status);
        AuditLog::where('auditable_type', Person::class)->whereIn('auditable_id', [$a->id, $b->id])->delete();
    }

    public function test_credit_needs_consent_and_shows_only_once_approved()
    {
        $m = $this->manuscript(['copy_date_raw' => 'احتمالاً ۸۹۷', 'copy_date_hijri_year' => 897]);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه‌ی نسخه', 'guest_name' => 'بی‌نام آزمون'])->assertOk();
        $this->suggest($m, ['field_name' => 'folios', 'suggested_value' => '25', 'rationale_citation' => 'شمارش برگ‌ها', 'credit_name' => '  پژوهشگر آزمون  '])->assertOk();
        [$anonymous, $credited] = FieldSuggestion::where('suggestable_id', $m->id)->orderBy('id')->get()->all();
        $this->assertNull($anonymous->credit_name);
        $this->assertSame('پژوهشگر آزمون', $credited->credit_name);

        // Pending suggestions are never shown
        $this->get(route('manuscripts.show', $m))->assertOk()->assertDontSee('suggestion-readings', false)
            // The parsed year repeats the catalog text, so it is not shown again in brackets
            ->assertDontSee('(897 هـ.ق)');

        $this->approve($anonymous, ['applied_value' => 'هرات']);
        $this->approve($credited, ['applied_value' => '25']);
        $this->assertSame('adopted', $credited->fresh()->standing);
        $this->assertSame('25', $credited->fresh()->applied_value);
        $this->get(route('manuscripts.show', $m))->assertOk()
            ->assertSee('id="suggestion-readings"', false)
            ->assertSee('اصلاح به پیشنهاد پژوهشگر آزمون')
            ->assertSee('شمارش برگ‌ها')
            ->assertSee('یکی از پژوهشگران')
            ->assertDontSee('بی‌نام آزمون');

        // The reviewer can add a name later, once the submitter agrees, or take it down again
        $this->actingAs($this->admin)->post("/admin/suggestions/{$anonymous->id}/credit", ['credit_name' => 'نام تازه'])->assertSessionHas('success');
        $this->post("/admin/suggestions/{$credited->id}/credit", ['credit_name' => ''])->assertSessionHas('success');
        $this->assertSame('نام تازه', $anonymous->fresh()->credit_name);
        $this->assertNull($credited->fresh()->credit_name);
        $this->get(route('manuscripts.show', $m))->assertSee('نام تازه')->assertDontSee('پژوهشگر آزمون');
    }

    public function test_adopting_a_new_reading_demotes_the_previous_one()
    {
        $m = $this->manuscript(['copy_place' => 'تبریز']);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه', 'credit_name' => 'نخستین'])->assertOk();
        $first = $this->pendingFor($m);
        $this->approve($first, ['applied_value' => 'هرات', 'outcome' => 'adopt']);

        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'مشهد', 'rationale_citation' => 'ترقیمه‌ی رساله‌ی دیگر', 'credit_name' => 'دومین'])->assertOk();
        $second = $this->pendingFor($m);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$second->id}")->assertOk()->assertSee('این فیلد قول مختار دارد');

        // The previous adopted reading must be given a standing, and the preference explained
        $this->approve($second, ['applied_value' => 'مشهد', 'outcome' => 'adopt'])->assertSessionHasErrors('previous_standing');
        $this->approve($second, ['applied_value' => 'مشهد', 'outcome' => 'adopt', 'previous_standing' => 'superseded'])->assertSessionHasErrors('public_note');
        $this->assertSame('pending', $second->fresh()->status);

        $this->approve($second, ['applied_value' => 'مشهد', 'outcome' => 'adopt', 'previous_standing' => 'superseded', 'public_note' => 'ترقیمه‌ی رساله‌ی دیگر روشن‌تر است'])->assertRedirect('/admin/suggestions');
        $this->assertSame('مشهد', $m->fresh()->copy_place);
        $this->assertSame('superseded', $first->fresh()->standing);
        $this->assertSame($second->id, $first->fresh()->superseded_by);

        $this->get(route('manuscripts.show', $m))->assertOk()
            ->assertSee('سابقه‌ی اصلاحات (1)')
            ->assertSee('جای خود را به قول «مشهد» داد')
            ->assertSee('ترقیمه‌ی رساله‌ی دیگر روشن‌تر است')
            // The catalog's own value is a reading too: «تبریز» beside «مشهد»
            ->assertSee('ضبط فهرست پیش از اصلاح')
            ->assertSee('2 قول');

        // Rolling the approval back restores the demoted reading
        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $m->id)->where('action', 'verify')->latest('id')->first();
        $this->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame('adopted', $first->fresh()->standing);
        $this->assertNull($first->fresh()->superseded_by);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertNull($second->fresh()->standing);
    }

    public function test_alternative_readings_stay_beside_the_record_and_can_be_adopted()
    {
        $m = $this->manuscript(['copy_place' => 'تبریز']);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'شیراز', 'rationale_citation' => 'فهرست دیگر', 'credit_name' => 'قائل دیگر'])->assertOk();
        $alt = $this->pendingFor($m);

        $this->approve($alt, ['applied_value' => 'شیراز', 'outcome' => 'alternative'])->assertSessionHasErrors('public_note');
        $this->approve($alt, ['applied_value' => 'شیراز', 'outcome' => 'alternative', 'public_note' => 'مستند فهرست چاپی قوی‌تر است'])->assertRedirect('/admin/suggestions');
        $this->assertSame('تبریز', $m->fresh()->copy_place);
        $this->assertSame('alternative', $alt->fresh()->standing);
        $this->assertSame(0, ScholarlyAnnotation::where('suggestion_id', $alt->id)->count());

        $this->get(route('manuscripts.show', $m))->assertOk()
            ->assertSee('ضبط فعلی (فهرست)')->assertSee('قول دیگر')->assertSee('شیراز')
            ->assertSee('مستند فهرست چاپی قوی‌تر است')->assertSee('2 قول');

        // Later evidence: the alternative is adopted from the same desk, and a rollback returns it to alternative
        $this->actingAs($this->admin)->get("/admin/suggestions/{$alt->id}")->assertOk()->assertSee('انتخاب این «قول دیگر» به‌عنوان قول مختار');
        $this->approve($alt, ['applied_value' => 'شیراز'])->assertRedirect('/admin/suggestions');
        $this->assertSame('شیراز', $m->fresh()->copy_place);
        $this->assertSame('adopted', $alt->fresh()->standing);
        $this->assertSame('مستند فهرست چاپی قوی‌تر است', $alt->fresh()->public_note);

        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $m->id)->where('action', 'verify')->latest('id')->first();
        $this->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame('تبریز', $m->fresh()->copy_place);
        $this->assertSame('approved', $alt->fresh()->status);
        $this->assertSame('alternative', $alt->fresh()->standing);
    }

    public function test_edits_fix_values_without_becoming_readings()
    {
        $m = $this->manuscript(['copy_place' => 'تبریز']);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه', 'credit_name' => 'قائل'])->assertOk();
        $reading = $this->pendingFor($m);
        $this->approve($reading, ['applied_value' => 'هرات کهن', 'outcome' => 'adopt']);

        // A user's typo report, approved as an edit, also fixes the adopted reading's value
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'هرات کهنه', 'rationale_citation' => 'غلط تایپی', 'credit_name' => 'ویراستار'])->assertOk();
        $edit = $this->pendingFor($m);
        $this->approve($edit, ['applied_value' => 'هرات کهنه', 'outcome' => 'ocr_fix']);
        $this->assertSame('edit', $edit->fresh()->standing);
        $this->assertSame('هرات کهنه', $m->fresh()->copy_place);
        $this->assertSame('هرات کهنه', $reading->fresh()->applied_value);

        $this->get(route('manuscripts.show', $m))->assertOk()
            ->assertSee('ویرایش به پیشنهاد')->assertSee('ویراستار')
            // The catalog value and the adopted reading; the edit is not a third
            ->assertSee('2 قول')->assertDontSee('3 قول');

        // A reviewer's direct edit: reviewers only, no suggestion, logged, and its rollback leaves suggestions alone
        $this->post('/logout');
        $direct = ['type' => 'manuscript', 'id' => $m->id, 'field_name' => 'copy_place', 'suggested_value' => 'هرات کهنهٔ'];
        $this->postJson('/admin/suggestions/direct-edit', $direct)->assertUnauthorized();
        $before = FieldSuggestion::where('suggestable_id', $m->id)->count();
        $this->actingAs($this->admin)->postJson('/admin/suggestions/direct-edit', $direct)->assertOk();
        $this->assertSame('هرات کهنهٔ', $m->fresh()->copy_place);
        $this->assertSame('هرات کهنهٔ', $reading->fresh()->applied_value);
        $this->assertSame($before, FieldSuggestion::where('suggestable_id', $m->id)->count());

        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $m->id)->where('action', 'verify')->latest('id')->first();
        $this->assertTrue($log->new_values['direct_edit']);
        $this->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame('هرات کهنه', $m->fresh()->copy_place);
        $this->assertSame('هرات کهنه', $reading->fresh()->applied_value);
        $this->assertSame('approved', $edit->fresh()->status);
    }

    public function test_users_see_and_manage_their_own_suggestions()
    {
        $user = User::firstOrCreate(['email' => 'test-researcher@fahares.net'], ['name' => 'پژوهشگر آزمون', 'password' => 'password123', 'role' => 'user']);
        $other = User::firstOrCreate(['email' => 'test-other@fahares.net'], ['name' => 'دیگری', 'password' => 'password123', 'role' => 'user']);
        $m = $this->manuscript();

        $this->actingAs($user);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه'])->assertOk();
        $mine = $this->pendingFor($m);
        $this->actingAs($other);
        $this->suggest($m, ['field_name' => 'folios', 'suggested_value' => '30', 'rationale_citation' => 'شمارش'])->assertOk();
        $theirs = $this->pendingFor($m);

        $this->post('/logout');
        $this->get('/my/suggestions')->assertRedirect('/login');

        $this->approve($mine, ['applied_value' => 'هرات', 'outcome' => 'adopt', 'public_note' => 'سپاس از دقت شما']);
        $this->actingAs($user)->get('/my/suggestions')->assertOk()
            ->assertSee('قول مختار')->assertSee('سپاس از دقت شما')->assertSee('هرات')
            ->assertDontSee('شمارش');

        $this->post("/my/suggestions/{$mine->id}/credit", ['credit_name' => 'دکتر آزمون'])->assertSessionHas('success');
        $this->assertSame('دکتر آزمون', $mine->fresh()->credit_name);
        $this->post("/my/suggestions/{$theirs->id}/credit", ['credit_name' => 'ربوده'])->assertForbidden();
        $this->assertNull($theirs->fresh()->credit_name);

        User::whereIn('id', [$user->id, $other->id])->delete();
    }

    public function test_corpus_syncs_keep_columns_fixed_by_reviewed_corrections()
    {
        $work = $this->work(['print_info' => 'چاپ فهرست']);
        $this->suggest($work, ['field_name' => 'print_info', 'suggested_value' => 'چاپ تهران ۱۳۲۰', 'rationale_citation' => 'شناسنامه‌ی چاپ'])->assertOk();
        $this->approve($this->pendingFor($work), ['applied_value' => 'چاپ تهران ۱۳۲۰', 'outcome' => 'adopt']);
        // A reviewer's direct edit is protected too
        $this->actingAs($this->admin)->postJson('/admin/suggestions/direct-edit', ['type' => 'work', 'id' => $work->id, 'field_name' => 'dedication', 'suggested_value' => 'به نام شاه'])->assertOk();

        $guard = \App\Suggestions\ProtectedColumns::for(Work::class);
        $payload = $guard->strip($work->id, ['print_info' => 'از پیکره', 'dedication' => 'از پیکره', 'raw_text' => 'متن تازه']);
        $this->assertSame(['raw_text' => 'متن تازه'], $payload);
        $this->assertSame(['print_info', 'dedication'], $guard->skipped()[$work->id]);
        $this->assertSame(['raw_text' => 'x'], $guard->strip($work->id + 1000000, ['raw_text' => 'x']));
        AuditLog::where('auditable_type', Work::class)->where('auditable_id', $work->id)->delete();
    }

    protected function researcher(string $email, string $role = 'user'): User
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => 'آزمونگر ' . $role, 'password' => 'password123', 'role' => $role]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_reviewers_are_notified_of_new_suggestions_by_their_preferences()
    {
        \Illuminate\Support\Facades\Notification::fake();
        config(['notifications.telegram.reviewers_chat_id' => '-100123']);
        $editor = $this->researcher('test-editor@fahares.net', 'editor');
        \App\Notifications\NotificationPreferences::set($editor, 'suggestion.submitted', 'mail', false);
        $m = $this->manuscript();

        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه'])->assertOk();

        $sent = \App\Notifications\SuggestionSubmitted::class;
        \Illuminate\Support\Facades\Notification::assertSentTo($this->admin, $sent, fn ($n, $channels) => $channels === ['database', 'mail']);
        \Illuminate\Support\Facades\Notification::assertSentTo($editor, $sent, fn ($n, $channels) => $channels === ['database']);
        \Illuminate\Support\Facades\Notification::assertSentOnDemand($sent, fn ($n, $channels, $notifiable) => ($notifiable->routes['telegram'] ?? null) === '-100123');

        // A reviewer's own suggestion does not notify them
        \Illuminate\Support\Facades\Notification::fake();
        $this->actingAs($this->admin);
        $this->suggest($m, ['field_name' => 'folios', 'suggested_value' => '12', 'rationale_citation' => 'شمارش'])->assertOk();
        \Illuminate\Support\Facades\Notification::assertNotSentTo($this->admin, $sent);

        $editor->delete();
    }

    public function test_submitters_hear_the_outcome_and_demoted_readings_are_reported()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->researcher('test-submitter@fahares.net');
        $m = $this->manuscript();
        $this->actingAs($user);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه'])->assertOk();
        $first = $this->pendingFor($m);
        $this->post('/logout');
        $this->approve($first, ['applied_value' => 'هرات', 'outcome' => 'adopt']);

        $reviewed = \App\Notifications\SuggestionReviewed::class;
        \Illuminate\Support\Facades\Notification::assertSentTo($user, $reviewed, fn ($n) => $n->event === 'reviewed' && $n->suggestion->is($first));

        // A guest who opted in hears by email; one who did not hears nothing
        $this->post('/logout');
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'مشهد', 'rationale_citation' => 'ترقیمه‌ی دیگر', 'guest_email' => 'guest-yes@example.com', 'notify_submitter' => 1])->assertOk();
        $second = $this->pendingFor($m);
        $this->suggest($m, ['field_name' => 'folios', 'suggested_value' => '9', 'rationale_citation' => 'شمارش', 'guest_email' => 'guest-no@example.com'])->assertOk();
        $silent = $this->pendingFor($m);
        $this->assertTrue($second->notify_submitter);
        $this->assertFalse($silent->notify_submitter);

        $this->approve($second, ['applied_value' => 'مشهد', 'outcome' => 'adopt', 'previous_standing' => 'alternative', 'public_note' => 'ترقیمه‌ی دوم روشن‌تر است']);
        $this->post("/admin/suggestions/{$silent->id}/reject", ['public_note' => 'نادرست']);

        \Illuminate\Support\Facades\Notification::assertSentOnDemand($reviewed, fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'guest-yes@example.com');
        \Illuminate\Support\Facades\Notification::assertNotSentTo(new \Illuminate\Notifications\AnonymousNotifiable, $reviewed, fn ($n, $c, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'guest-no@example.com');
        // The first submitter learns their reading became an alternative, with the reviewer's reason
        \Illuminate\Support\Facades\Notification::assertSentTo($user, $reviewed, function ($n) use ($user, $first) {
            $lines = $n->toArray($user)['body'];

            return $n->event === 'demoted' && $n->suggestion->is($first) && str_contains($lines, 'ترقیمه‌ی دوم روشن‌تر است');
        });

        $user->delete();
    }

    public function test_notification_pages_settings_and_unsubscribe()
    {
        $user = $this->researcher('test-notified@fahares.net');
        $m = $this->manuscript();
        $s = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place', 'suggested_value' => 'هرات', 'status' => 'rejected', 'public_note' => 'مستند کافی نبود', 'user_id' => $user->id]);

        // Mail renders right-to-left with an unsubscribe link; database notifications are listed and then read
        $mail = (new \App\Notifications\SuggestionReviewed($s))->toMail($user)->render();
        $this->assertStringContainsString('dir="rtl"', (string) $mail);
        $this->assertStringContainsString('notifications/unsubscribe', (string) $mail);

        $user->notify(new \App\Notifications\SuggestionReviewed($s));
        $this->assertSame(1, $user->unreadNotifications()->count());
        $this->actingAs($user)->get('/notifications')->assertOk()->assertSee('پذیرفته نشد')->assertSee('مستند کافی نبود');
        $this->assertSame(0, $user->unreadNotifications()->count());

        $this->get('/settings/notifications')->assertOk()->assertSee('نتیجه‌ی داوری پیشنهادهای من')->assertDontSee('پیشنهاد اصلاحی تازه برای داوری');
        $this->post('/settings/notifications', ['enabled' => ['suggestion.reviewed' => ['database' => 1]]])->assertSessionHas('success');
        $this->assertSame(['database'], \App\Notifications\NotificationPreferences::channelsFor($user, 'suggestion.reviewed'));

        $this->post('/logout');
        $url = \Illuminate\Support\Facades\URL::signedRoute('notifications.unsubscribe', ['user' => $user->id, 'type' => 'suggestion.reviewed', 'channel' => 'database']);
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->get($url)->assertOk()->assertSee('اشتراک لغو شد');
        $this->assertSame([], \App\Notifications\NotificationPreferences::channelsFor($user, 'suggestion.reviewed'));

        $user->notifications()->delete();
        $user->delete();
    }
}
