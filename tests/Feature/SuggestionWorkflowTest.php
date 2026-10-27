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
        // Verified, so the name asked for beside a suggestion is published at once (HeldCredit)
        $this->admin->forceFill(['role' => 'admin', 'email_verified_at' => $this->admin->email_verified_at ?? now()])->save();
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
            AuditLog::where('auditable_type', FieldSuggestion::class)->whereIn('auditable_id', $suggestionIds)->delete();
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

        return $this->suggestJson($data + ['type' => $type, 'id' => $record->id, 'kind' => 'field']);
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

        $this->suggest($m, ['field_name' => 'dimensions', 'suggested_value' => '۱۲×۱۸', 'rationale_citation' => 'منبع آزمایشی', 'current_value' => 'جعلی'])
            ->assertOk();

        $s = FieldSuggestion::where('suggestable_id', $m->id)->sole();
        $this->assertSame('field', $s->kind);
        $this->assertSame('۱۰×۱۵', $s->current_value, 'current value is read from the record, not the request');
    }

    public function test_general_and_legacy_suggestions_need_no_field()
    {
        $m = $this->manuscript();

        $this->suggestJson(['type' => 'manuscript', 'id' => $m->id, 'kind' => 'general', 'suggested_value' => 'این نسخه از اثر دیگری است'])
            ->assertOk();
        // A page cached before the per-field desk posts the class name and the old "other" field
        $this->suggestPost([
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
        $this->suggestJson(['type' => 'manuscript', 'id' => $m->id, 'kind' => 'general', 'suggested_value' => 'رکورد تکراری است'])->assertOk();

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

        return $this->suggestJson($extra + [
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

        $this->relate($library, 'move_to_work', $work)->assertStatus(422)->assertJsonValidationErrors('field_name');
        $this->relate($library, 'duplicate', $library)->assertStatus(422)->assertJsonValidationErrors('target_id');
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
        $this->actingAs($this->admin)->get("/admin/suggestions/{$second->id}")->assertOk()->assertSee('این فیلد مقدار منتخب دارد');

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
        $this->actingAs($this->admin)->get("/admin/suggestions/{$alt->id}")->assertOk()->assertSee('انتخاب این «قول دیگر» به‌عنوان مقدار منتخب');
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

    public function test_a_mistaken_rejection_returns_to_the_queue()
    {
        $m = $this->manuscript(['copy_place' => 'تبریز']);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه'])->assertOk();
        $s = $this->pendingFor($m);
        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/reopen")->assertStatus(409);

        $this->post("/admin/suggestions/{$s->id}/reject", ['public_note' => 'مستند ناکافی', 'admin_notes' => 'اشتباه'])->assertRedirect('/admin/suggestions');
        $this->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('لغو رد و بازگشت به صف داوری');

        $this->post("/admin/suggestions/{$s->id}/reopen")->assertRedirect("/admin/suggestions/{$s->id}")->assertSessionHas('success');
        $s->refresh();
        $this->assertSame('pending', $s->status);
        $this->assertNull($s->public_note);
        $this->assertNull($s->reviewed_at);
        $this->assertSame('rejected', AuditLog::where('auditable_type', FieldSuggestion::class)->where('auditable_id', $s->id)->sole()->old_values['status']);

        // Reviewed again, now approved
        $this->approve($s, ['applied_value' => 'هرات', 'outcome' => 'adopt'])->assertRedirect('/admin/suggestions');
        $this->assertSame('هرات', $m->fresh()->copy_place);
    }

    public function test_a_mistaken_approval_is_undone_from_the_suggestion_page()
    {
        $old = $this->person('کاتب پیشین');
        $new = $this->person('کاتب تازه');
        $m = $this->manuscript(['scribe_id' => $old->id, 'scribe_name' => $old->name]);
        $old->recountContributions();
        $this->suggest($m, ['field_name' => 'scribe_name', 'suggested_value' => $new->name, 'rationale_citation' => 'ترقیمه', 'person_id' => $new->id])->assertOk();
        $s = $this->pendingFor($m);
        $this->approve($s, ['person_pick' => 'p:' . $new->id])->assertRedirect('/admin/suggestions');
        $this->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('لغو تأیید و بازگشت به صف داوری');

        $this->post("/admin/suggestions/{$s->id}/reopen")->assertSessionHas('success');
        $this->assertSame($old->id, $m->fresh()->scribe_id);
        $this->assertSame(1, $old->fresh()->manuscripts_count);
        $this->assertSame(0, $new->fresh()->manuscripts_count);
        $this->assertSame('pending', $s->fresh()->status);

        // The audit log refuses to undo the same approval a second time
        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $m->id)->where('action', 'verify')->sole();
        $this->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('error');
        $this->assertSame($old->id, $m->fresh()->scribe_id);
    }

    public function test_undoing_an_approval_waits_for_later_changes_to_the_same_field()
    {
        $m = $this->manuscript(['copy_place' => 'تبریز']);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه'])->assertOk();
        $first = $this->pendingFor($m);
        $this->approve($first, ['applied_value' => 'هرات', 'outcome' => 'adopt']);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'مشهد', 'rationale_citation' => 'ترقیمه‌ی دیگر'])->assertOk();
        $second = $this->pendingFor($m);
        $this->approve($second, ['applied_value' => 'مشهد', 'outcome' => 'adopt', 'previous_standing' => 'superseded', 'public_note' => 'روشن‌تر است']);

        // The first approval cannot be undone over the second
        $this->post("/admin/suggestions/{$first->id}/reopen")->assertSessionHas('error');
        $this->assertSame('مشهد', $m->fresh()->copy_place);
        $this->assertSame('approved', $first->fresh()->status);

        $this->post("/admin/suggestions/{$second->id}/reopen")->assertSessionHas('success');
        $this->assertSame('هرات', $m->fresh()->copy_place);
        $this->assertSame('adopted', $first->fresh()->standing);
        $this->post("/admin/suggestions/{$first->id}/reopen")->assertSessionHas('success');
        $this->assertSame('تبریز', $m->fresh()->copy_place);
        $this->assertSame('pending', $first->fresh()->status);
    }

    public function test_taking_back_an_adopted_alternative_keeps_it_an_alternative()
    {
        $m = $this->manuscript(['copy_place' => 'تبریز']);
        $this->suggest($m, ['field_name' => 'copy_place', 'suggested_value' => 'شیراز', 'rationale_citation' => 'فهرست دیگر'])->assertOk();
        $alt = $this->pendingFor($m);
        $this->approve($alt, ['applied_value' => 'شیراز', 'outcome' => 'alternative', 'public_note' => 'مستند فهرست چاپی قوی‌تر است']);
        $this->approve($alt, ['applied_value' => 'شیراز']);
        $this->assertSame('شیراز', $m->fresh()->copy_place);
        $this->get("/admin/suggestions/{$alt->id}")->assertOk()->assertSee('بازگرداندن به «قول دیگر»');

        $this->post("/admin/suggestions/{$alt->id}/reopen")->assertSessionHas('success');
        $this->assertSame('تبریز', $m->fresh()->copy_place);
        $this->assertSame('approved', $alt->fresh()->status);
        $this->assertSame('alternative', $alt->fresh()->standing);

        // Once more: the alternative itself goes back to the queue
        $this->post("/admin/suggestions/{$alt->id}/reopen")->assertSessionHas('success');
        $this->assertSame('pending', $alt->fresh()->status);
        $this->assertNull($alt->fresh()->standing);
        $this->assertSame('تبریز', $m->fresh()->copy_place);
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
        $user = $this->researcher('test-researcher@fahares.net');
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
            ->assertSee('مقدار منتخب')->assertSee('سپاس از دقت شما')->assertSee('هرات')
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
        $user->forceFill(['role' => $role, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

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

        // A guest who opted in, before guests stopped suggesting (backlog item 76), hears by email; one who did not hears nothing
        $this->post('/logout');
        $legacy = ['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'status' => 'pending', 'guest_name' => 'پژوهشگر مهمان'];
        $second = FieldSuggestion::create($legacy + ['field_name' => 'copy_place', 'current_value' => 'هرات', 'suggested_value' => 'مشهد', 'rationale_citation' => 'ترقیمه‌ی دیگر', 'guest_email' => 'guest-yes@example.com', 'notify_submitter' => true]);
        $silent = FieldSuggestion::create($legacy + ['field_name' => 'folios', 'suggested_value' => '9', 'rationale_citation' => 'شمارش', 'guest_email' => 'guest-no@example.com', 'notify_submitter' => false]);

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

    public function test_verified_email_claims_earlier_guest_suggestions()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $m = $this->manuscript();
        // Sent as a guest before suggestions needed an account (backlog item 76)
        $guest = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه', 'status' => 'pending', 'guest_name' => 'پژوهشگر مهمان', 'guest_email' => 'Claimer@Example.com']);

        // Registering sends the verification link but claims nothing: anyone can type someone else's email
        $this->post('/register', ['name' => 'مدعی', 'email' => 'claimer@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertRedirect();
        $user = User::where('email', 'claimer@example.com')->firstOrFail();
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
        $this->assertNull($guest->fresh()->user_id);
        $this->get('/my/suggestions')->assertSee('ایمیل حساب شما هنوز تأیید نشده است');

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect('/my/suggestions')->assertSessionHas('success', fn ($m) => str_contains($m, '1 پیشنهادی'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertSame($user->id, $guest->fresh()->user_id);
        $this->get('/my/suggestions')->assertSee('هرات')->assertDontSee('ایمیل حساب شما هنوز تأیید نشده است');

        $user->delete();
    }

    protected function fakeGoogle(string $id, string $email, ?bool $verified = true): void
    {
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'x']);
        $google = (new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => $verified])->map(['id' => $id, 'name' => 'کاربر گوگل', 'email' => $email]);
        $provider = \Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->andReturn($google);
        // A fresh factory each time, so a later call replaces the earlier Google user
        $factory = \Mockery::mock(\Laravel\Socialite\Contracts\Factory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);
        \Laravel\Socialite\Facades\Socialite::swap($factory);
    }

    public function test_google_sign_in_creates_or_links_accounts()
    {
        $m = $this->manuscript();
        $guest = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place', 'suggested_value' => 'هرات', 'rationale_citation' => 'ترقیمه', 'status' => 'pending', 'guest_name' => 'پژوهشگر مهمان', 'guest_email' => 'g-new@example.com']);

        // The button only appears once a Google client is configured
        config(['services.google.client_id' => null]);
        $this->get('/login')->assertOk()->assertDontSee('ورود با حساب گوگل');
        $this->get('/auth/google')->assertNotFound();

        // A new Google account: created, verified, signed in, and its guest suggestions claimed
        $this->fakeGoogle('g-111', 'G-New@example.com');
        $this->get('/login')->assertSee('ورود با حساب گوگل');
        $this->get('/auth/google/callback')->assertRedirect()->assertSessionHas('success');
        $created = User::where('email', 'g-new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($created);
        $this->assertSame('g-111', $created->google_id);
        $this->assertTrue($created->hasVerifiedEmail());
        $this->assertSame($created->id, $guest->fresh()->user_id);

        // An existing account with the same email is linked, not duplicated
        $this->post('/logout');
        $existing = $this->researcher('g-old@example.com');
        $this->fakeGoogle('g-222', 'g-old@example.com');
        $this->get('/auth/google/callback')->assertRedirect();
        $this->assertSame('g-222', $existing->fresh()->google_id);
        $this->assertSame(1, User::where('email', 'g-old@example.com')->count());

        // A Google account without a verified email is refused
        $this->post('/logout');
        $this->fakeGoogle('g-333', 'g-unverified@example.com', false);
        $this->get('/auth/google/callback')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'g-unverified@example.com')->count());

        User::whereIn('email', ['g-new@example.com', 'g-old@example.com'])->delete();
    }

    public function test_forgotten_password_is_reset_by_email_link()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->researcher('test-forgot@fahares.net');
        $this->get('/login')->assertSee('گذرواژه را فراموش کرده‌اید؟');

        // The same answer for unknown addresses, so the form does not reveal who has an account
        $this->post('/forgot-password', ['email' => 'nobody-here@example.com'])->assertSessionHas('success');
        $this->post('/forgot-password', ['email' => 'Test-Forgot@fahares.net'])->assertSessionHas('success');

        $token = null;
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class, function ($n) use (&$token, $user) {
            $token = $n->token;

            return str_contains((string) $n->toMail($user)->render(), 'dir="rtl"');
        });

        $this->post('/reset-password', ['token' => 'wrong', 'email' => $user->email, 'password' => 'newpassword9', 'password_confirmation' => 'newpassword9'])->assertSessionHasErrors('email');
        $this->get("/reset-password/{$token}?email=" . urlencode($user->email))->assertOk()->assertSee('تعیین گذرواژه‌ی تازه');
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'newpassword9', 'password_confirmation' => 'newpassword9'])->assertRedirect('/login');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpassword9', $user->fresh()->password));

        $user->delete();
    }

    protected function addNote($record, string $noteType, array $note, array $extra = [])
    {
        $type = \App\Suggestions\SuggestionRegistry::typeKey($record::class);

        return $this->suggestPost($extra + ['type' => $type, 'id' => $record->id, 'kind' => 'note', 'field_name' => $noteType, 'note' => $note], ['Accept' => 'application/json']);
    }

    public function test_research_notes_are_reviewed_edited_and_published()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $m = $this->manuscript();
        $this->get(route('manuscripts.show', $m))->assertOk()->assertSee('یادداشت‌ها و منابع پژوهشی')->assertSee('افزودن یادداشت یا منبع');

        // Validation: allowed types per record, required fields, http links
        $this->addNote($m, 'link', ['title' => 'x', 'url' => 'https://a.b'])->assertStatus(422)->assertJsonValidationErrors('field_name');
        $this->addNote($m, 'study', ['author' => 'بی‌عنوان'])->assertStatus(422)->assertJsonValidationErrors('note.title');
        $this->addNote($m, 'study', ['title' => 'مقاله', 'url' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('note.url');

        $this->addNote($m, 'observation', ['text' => "در برگ ۲۰ یادداشتی به خط علامه‌ی مجلسی است", 'location' => 'برگ ۲۰ پ'], ['credit_name' => 'نسخه‌پژوه آزمون'])->assertOk();
        $note = FieldSuggestion::where('suggestable_id', $m->id)->where('kind', 'note')->sole();
        $this->assertSame('pending', $note->status);
        $this->assertStringContainsString('علامه‌ی مجلسی', $note->suggested_value);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->admin, \App\Notifications\SuggestionSubmitted::class);
        $this->get(route('manuscripts.show', $m))->assertDontSee('علامه‌ی مجلسی');

        // The reviewer corrects the text and publishes; the submitter's version is kept
        $this->actingAs($this->admin)->get("/admin/suggestions/{$note->id}")->assertOk()->assertSee('تأیید و انتشار در صفحه‌ی رکورد');
        $this->post("/admin/suggestions/{$note->id}/note", ['note' => ['text' => 'در برگ ۲۰ یادداشتی به خط علامه محمدباقر مجلسی است', 'location' => 'برگ ۲۰ پ'], 'public_note' => 'خط با نمونه‌های دیگر سنجیده شد'])->assertRedirect();
        $note->refresh();
        $this->assertSame('approved', $note->status);
        $this->assertStringContainsString('علامه‌ی مجلسی', $note->resolution['original_payload']['text']);
        $this->get(route('manuscripts.show', $m))->assertOk()
            ->assertSee('علامه محمدباقر مجلسی')->assertSee('نسخه‌پژوه آزمون')->assertSee('خط با نمونه‌های دیگر سنجیده شد')->assertSee('مشاهده و نکته‌ی پژوهشی');

        // Withdrawn notes leave the page and can be published again
        $this->post("/admin/suggestions/{$note->id}/withdraw")->assertRedirect();
        $this->get(route('manuscripts.show', $m))->assertDontSee('علامه محمدباقر مجلسی');
        $this->post("/admin/suggestions/{$note->id}/note", ['note' => $note->payload])->assertRedirect();
        $this->get(route('manuscripts.show', $m))->assertSee('علامه محمدباقر مجلسی');
    }

    public function test_image_notes_upload_only_for_signed_in_users_and_stay_hidden_until_published()
    {
        \Illuminate\Support\Facades\Notification::fake();
        \Illuminate\Support\Facades\Storage::fake('public');
        $m = $this->manuscript();
        // A real 1×1 JPEG: the container has no GD for UploadedFile::fake()->image()
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $file = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('page.jpg', $jpeg);

        // Guests send nothing, images or otherwise (backlog item 76)
        $this->post('/suggestions', ['type' => 'manuscript', 'id' => $m->id, 'kind' => 'note', 'field_name' => 'image', 'note' => ['caption' => 'برگ نخست'], 'files' => [$file()], 'rights_confirmed' => 1], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->assertSame(0, FieldSuggestion::where('suggestable_id', $m->id)->where('suggestable_type', Manuscript::class)->count());

        $user = $this->researcher('test-uploader@fahares.net');
        $this->actingAs($user);
        $this->addNote($m, 'image', ['caption' => 'بی‌پیوند و بی‌تصویر'])->assertStatus(422)->assertJsonValidationErrors('note.url');
        $this->addNote($m, 'image', ['caption' => 'برگ نخست'], ['files' => [$file()]])->assertStatus(422)->assertJsonValidationErrors('rights_confirmed');
        $this->addNote($m, 'image', ['caption' => 'برگ نخست'], ['files' => [$file(), $file()], 'rights_confirmed' => 1])->assertOk();
        $note = FieldSuggestion::where('suggestable_id', $m->id)->where('kind', 'note')->sole();
        $this->assertCount(2, $note->payload['files']);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($note->payload['files'][0]);

        // Before publication only the uploader and reviewers see the files
        $this->get(route('notes.file', [$note, 0]))->assertOk();
        $this->post('/logout');
        $this->get(route('notes.file', [$note, 0]))->assertNotFound();
        $this->actingAs($this->admin)->get(route('notes.file', [$note, 1]))->assertOk();

        // The reviewer drops one image and publishes; the page links the image from its header
        $this->post("/admin/suggestions/{$note->id}/note", ['note' => ['caption' => 'برگ نخست و سرلوح'], 'remove_files' => [1]])->assertRedirect();
        $note->refresh();
        $this->assertCount(1, $note->payload['files']);
        $this->post('/logout');
        $this->get(route('notes.file', [$note, 0]))->assertOk();
        $this->get(route('manuscripts.show', $m))->assertSee('href="#research-notes"', false)->assertSee('تصویر نسخه')->assertSee('برگ نخست و سرلوح');

        // A rejected note's uploads are deleted
        $this->actingAs($user);
        $this->addNote($m, 'image', ['caption' => 'نامربوط'], ['files' => [$file()], 'rights_confirmed' => 1])->assertOk();
        $rejected = FieldSuggestion::where('suggestable_id', $m->id)->where('kind', 'note')->where('status', 'pending')->sole();
        $path = $rejected->payload['files'][0];
        $this->actingAs($this->admin)->post("/admin/suggestions/{$rejected->id}/reject", ['public_note' => 'تصویر از نسخه‌ی دیگری است']);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($path);

        $user->delete();
    }

    public function test_reviewer_converts_a_misfiled_suggestion_keeping_the_submitter()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->researcher('test-misfiled@fahares.net');
        $m = $this->manuscript();
        $m->update(['copy_date_raw' => '۹۰۰ق']);
        $this->actingAs($user);
        // A scribe's name and a copy date, sent together as a research note
        $this->addNote($m, 'observation', ['text' => 'کاتب این نسخه عبدالله بن محمود است و تاریخ کتابت ۸۹۷ است'], ['credit_name' => 'پژوهشگر آزمون'])->assertOk();
        $note = FieldSuggestion::where('suggestable_id', $m->id)->sole();
        $this->post('/logout');
        \Illuminate\Support\Facades\Notification::fake();

        $this->actingAs($this->admin)->get("/admin/suggestions/{$note->id}")->assertOk()->assertSee('تبدیل به پیشنهاد دیگر');

        // First split: the scribe, keeping the original open
        $this->post("/admin/suggestions/{$note->id}/convert", ['target_type' => 'manuscript', 'kind' => 'field', 'field_name' => 'scribe_name', 'suggested_value' => 'عبدالله بن محمود', 'rationale_citation' => 'یادداشت پژوهشگر'])->assertRedirect();
        $scribe = FieldSuggestion::where('suggestable_id', $m->id)->where('field_name', 'scribe_name')->sole();
        $this->assertSame('pending', $note->fresh()->status);
        $this->assertSame([$user->id, 'پژوهشگر آزمون', $note->id], [$scribe->user_id, $scribe->credit_name, $scribe->payload['derived_from']]);
        $this->assertTrue($scribe->created_at->equalTo($note->created_at));

        // Second split closes the original; a work-level note goes to another record by link
        $this->post("/admin/suggestions/{$note->id}/convert", ['target_type' => 'manuscript', 'kind' => 'field', 'field_name' => 'copy_date_raw', 'suggested_value' => '۸۹۷', 'close_original' => 1])->assertRedirect();
        $this->assertSame('converted', $note->fresh()->status);
        $this->assertCount(2, $note->fresh()->resolution['converted_to']);
        // The derived field suggestion records the field's value at conversion time, not an empty one
        $this->assertSame('۹۰۰ق', FieldSuggestion::where('suggestable_id', $m->id)->where('field_name', 'copy_date_raw')->sole()->current_value);
        $this->post("/admin/suggestions/{$note->id}/convert", ['target_type' => 'manuscript', 'kind' => 'general', 'suggested_value' => 'x'])->assertStatus(409);

        // Converting is the reviewer's own work: no "new suggestion" notifications
        \Illuminate\Support\Facades\Notification::assertNothingSent();

        // Approving the derived suggestion credits the original submitter
        $this->approve($scribe, ['applied_value' => 'عبدالله بن محمود', 'outcome' => 'adopt', 'person_pick' => 'text']);
        $this->get(route('manuscripts.show', $m))->assertSee('اصلاح به پیشنهاد پژوهشگر آزمون');
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\SuggestionReviewed::class);

        $this->actingAs($user)->get('/my/suggestions')->assertOk()->assertSee('تبدیل شد')->assertSee('ساخته‌شده از پیشنهاد #' . $note->id);

        // A pasted link moves a note to another record, and a type not allowed there is refused
        $other = $this->work();
        $this->actingAs($this->admin);
        $this->addNote($m, 'observation', ['text' => 'این اثر در الذریعه ج ۵ ص ۱۲ معرفی شده']);
        $misplaced = FieldSuggestion::where('suggestable_id', $m->id)->where('kind', 'note')->where('status', 'pending')->latest('id')->first();
        $this->post("/admin/suggestions/{$misplaced->id}/convert", ['target_type' => 'work', 'target_ref' => "https://fahares.net/w/{$other->id}", 'kind' => 'note', 'field_name' => 'image', 'note' => ['url' => 'https://a.b']])->assertSessionHasErrors('field_name');
        $this->post("/admin/suggestions/{$misplaced->id}/convert", ['target_type' => 'work', 'target_ref' => "https://fahares.net/w/{$other->id}", 'kind' => 'note', 'field_name' => 'catalog', 'note' => ['title' => 'الذریعه', 'volume' => '۵', 'page' => '۱۲'], 'close_original' => 1])->assertRedirect();
        $moved = FieldSuggestion::where('suggestable_type', Work::class)->where('suggestable_id', $other->id)->sole();
        $this->assertSame(['catalog', 'الذریعه'], [$moved->field_name, $moved->payload['title']]);

        $user->delete();
    }

    public function test_reviewer_converts_a_note_into_a_relation_keeping_the_submitter()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->researcher('test-note-relation@fahares.net');
        $m = $this->manuscript();
        $from = $m->work;
        $to = $this->work();
        $this->actingAs($user);
        // The researcher says, in a note, that the copy belongs to another work
        $this->addNote($m, 'observation', ['text' => "این نسخه از اثر {$to->id} است، نه {$from->id}"], ['credit_name' => 'پژوهشگر آزمون'])->assertOk();
        $note = FieldSuggestion::where('suggestable_id', $m->id)->sole();
        $this->post('/logout');

        $this->actingAs($this->admin);
        $convert = fn (array $data) => $this->post("/admin/suggestions/{$note->id}/convert", $data + ['target_type' => 'manuscript', 'kind' => 'relation', 'field_name' => 'move_to_work', 'close_original' => 1]);
        // The relation must exist for the record type, and its target must be given, readable and not the record itself
        $convert(['field_name' => 'renamed', 'relation_target' => (string) $to->id])->assertSessionHasErrors('field_name');
        $convert([])->assertSessionHasErrors('target_id');
        $convert(['relation_target' => 'fahares.net/x/abc'])->assertSessionHasErrors('relation_target');
        $convert(['field_name' => 'duplicate', 'relation_target' => "https://fahares.net/m/{$m->id}"])->assertSessionHasErrors('target_id');
        $convert(['field_name' => 'manuscript_relation', 'relation_target' => (string) $this->manuscript()->id])->assertSessionHasErrors('role');
        $this->assertSame('pending', $note->fresh()->status);

        $convert(['relation_target' => "https://fahares.net/w/{$to->id}", 'suggested_value' => '', 'rationale_citation' => 'یادداشت پژوهشگر'])->assertRedirect();
        $move = FieldSuggestion::where('suggestable_id', $m->id)->where('kind', 'relation')->sole();
        $this->assertSame(['move_to_work', $to->id, $note->id, 'این نسخه از اثر دیگری است'], [$move->field_name, $move->payload['target_id'], $move->payload['derived_from'], $move->suggested_value]);
        $this->assertSame([$user->id, 'پژوهشگر آزمون'], [$move->user_id, $move->credit_name]);
        $this->assertTrue($move->created_at->equalTo($note->created_at));
        $this->assertSame('converted', $note->fresh()->status);

        // Approving it moves the copy and tells the original researcher
        $this->get("/admin/suggestions/{$move->id}")->assertOk()->assertSee('انتقال نسخه به اثر دیگر');
        $this->approve($move)->assertRedirect('/admin/suggestions');
        $this->assertSame($to->id, $m->fresh()->work_id);
        $this->assertSame('approved', $move->fresh()->status);
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\SuggestionReviewed::class);

        $user->delete();
    }

    protected function rollbackLatest($record): void
    {
        $log = AuditLog::where('auditable_type', $record::class)->where('auditable_id', $record->id)->where('action', 'verify')->latest('id')->first();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
    }

    public function test_linking_a_manuscript_scribe_to_a_person_and_back()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $person = $this->person('عبدالله بن محمود');
        $m = $this->manuscript(['scribe_name' => 'عبدالله']);
        // The catalog's name, kept as text without a person record
        DB::table('manuscript_scribe')->insert(['manuscript_id' => $m->id, 'person_id' => null, 'name' => 'عبدالله', 'position' => 0]);

        $this->relate($m, 'scribe_is', $person)->assertOk();
        $s = $this->pendingFor($m);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('کاتب اصلی این نسخه');
        $this->approve($s)->assertRedirect('/admin/suggestions');

        $m->refresh();
        $this->assertSame($person->id, $m->scribe_id);
        $this->assertSame('عبدالله', $m->scribe_name);
        $this->assertSame([$person->id], DB::table('manuscript_scribe')->where('manuscript_id', $m->id)->pluck('person_id')->all());
        $this->assertSame(1, $person->fresh()->manuscripts_count);

        $this->rollbackLatest($m);
        $this->assertNull($m->fresh()->scribe_id);
        $this->assertSame(['عبدالله'], DB::table('manuscript_scribe')->where('manuscript_id', $m->id)->pluck('name')->all());
        $this->assertSame(0, $person->fresh()->manuscripts_count);
    }

    public function test_adding_a_second_scribe_and_replacing_an_author()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $first = $this->person('کاتب نخست');
        $second = $this->person('کاتب دوم');
        $m = $this->manuscript(['scribe_name' => $first->name, 'scribe_id' => $first->id]);

        $this->relate($m, 'scribe_also', $second)->assertOk();
        $s = $this->pendingFor($m);
        $this->approve($s, ['contributor_note' => 'از برگ ۴۰ تا پایان'])->assertRedirect('/admin/suggestions');
        $rows = DB::table('manuscript_scribe')->where('manuscript_id', $m->id)->orderBy('position')->get();
        $this->assertSame([$first->id, $second->id], $rows->pluck('person_id')->all());
        $this->assertSame('از برگ ۴۰ تا پایان', $rows[1]->note);
        $this->get(route('manuscripts.show', $m))->assertSee('کاتب دوم')->assertSee('از برگ ۴۰ تا پایان');

        // The same person twice is refused; a rollback removes the added row only
        $this->relate($m, 'scribe_also', $second)->assertOk();
        $this->approve($this->pendingFor($m))->assertSessionHasErrors('applied_value');
        $this->rollbackLatest($m);
        $this->assertSame([$first->id], DB::table('manuscript_scribe')->where('manuscript_id', $m->id)->pluck('person_id')->all());
        $this->assertSame(0, $second->fresh()->manuscripts_count);

        // A work's attribution moves to another person, and both counts follow
        $old = $this->person('مؤلف منسوب');
        $real = $this->person('مؤلف درست');
        $work = $this->work(['author_name' => $old->name, 'author_id' => $old->id]);
        $old->recountContributions();
        $this->relate($work, 'author_is', $real)->assertOk();
        $this->approve($this->pendingFor($work))->assertRedirect('/admin/suggestions');
        $this->assertSame($real->id, $work->fresh()->author_id);
        $this->assertSame([0, 1], [$old->fresh()->works_count, $real->fresh()->works_count]);
    }

    public function test_another_name_of_a_person()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $p = $this->person('محمد بن حسین عاملی');

        // No target to pick: the name itself is required
        $this->relate($p, 'alias', null, ['suggested_value' => ''])->assertStatus(422)->assertJsonValidationErrors('suggested_value');
        $this->relate($p, 'alias', null, ['suggested_value' => 'شیخ بهائی'])->assertOk();
        $s = $this->pendingFor($p);
        $this->assertNull($s->payload['target_id'] ?? null);

        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('افزودن نام دیگر');
        $this->approve($s, ['alias' => 'شیخ بهائی'])->assertRedirect('/admin/suggestions');
        $this->assertContains('شیخ بهائی', $p->fresh()->aliases);

        // A name the person already has is refused
        $this->relate($p, 'alias', null, ['suggested_value' => 'شیخ  بهائی'])->assertOk();
        $this->approve($this->pendingFor($p), ['alias' => 'شیخ  بهائی'])->assertSessionHasErrors('applied_value');

        $this->rollbackLatest($p);
        $this->assertNotContains('شیخ بهائی', (array) $p->fresh()->aliases);
    }

    public function test_site_feedback_from_any_page()
    {
        \Illuminate\Support\Facades\Notification::fake();
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->get('/')->assertOk()->assertSee('گزارش اشکال یا پیشنهاد');

        // Only the path on this site is kept; guests cannot attach files
        $this->postJson('/feedback', ['category' => 'bug', 'message' => 'x'])->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postJson('/feedback', ['category' => 'bug', 'message' => 'جستجو با ی عربی نتیجه نمی‌دهد', 'page_url' => 'https://evil.example/search?q=کتاب', 'page_title' => 'جستجو', 'guest_email' => 'fb-guest@example.com', 'notify_submitter' => 1])->assertOk();
        $guest = FieldSuggestion::where('kind', 'feedback')->latest('id')->first();
        $this->assertNull($guest->suggestable_type);
        $this->assertSame('/search?q=کتاب', $guest->payload['url']);
        $this->assertTrue($guest->notify_submitter);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->admin, \App\Notifications\SuggestionSubmitted::class, fn ($n) => str_contains($n->toArray($this->admin)['title'], 'بازخورد تازه'));

        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $shot = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('shot.jpg', $jpeg);
        $this->postJson('/feedback', ['category' => 'bug', 'message' => 'تصویر پیوست', 'files' => [$shot()]])->assertStatus(422)->assertJsonValidationErrors('files');

        $user = $this->researcher('test-feedback@fahares.net');
        $this->actingAs($user)->postJson('/feedback', ['category' => 'idea', 'message' => 'کاش فیلتر سده هم داشت', 'page_url' => '/subjects', 'page_title' => 'موضوعات', 'files' => [$shot()]])->assertOk();
        $mine = FieldSuggestion::where('kind', 'feedback')->where('user_id', $user->id)->sole();
        $this->get(route('notes.file', [$mine, 0]))->assertOk();
        $this->get('/my/suggestions')->assertSee('صفحه: موضوعات')->assertSee('پیشنهاد برای سایت');

        // Reviewers filter and resolve it; the sender gets the answer; screenshots stay private
        $this->actingAs($this->admin)->get('/admin/suggestions?type=feedback')->assertOk()->assertSee('صفحه: موضوعات');
        $this->get("/admin/suggestions/{$mine->id}")->assertOk()->assertSee('ثبت «رسیدگی شد»')->assertSee('/subjects');
        $this->post("/admin/suggestions/{$mine->id}/resolve", ['public_note' => 'در نسخه‌ی بعد افزوده می‌شود'])->assertRedirect();
        $this->assertSame('approved', $mine->fresh()->status);
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\SuggestionReviewed::class, fn ($n) => str_contains($n->toArray($user)['body'], 'در نسخه‌ی بعد افزوده می‌شود'));
        $this->post('/logout');
        $this->get(route('notes.file', [$mine, 0]))->assertNotFound();

        // A data error reported from a page converts into a record suggestion; a rejected report loses its files
        $m = $this->manuscript();
        $this->actingAs($this->admin)->post("/admin/suggestions/{$guest->id}/convert", ['target_type' => 'manuscript', 'target_ref' => (string) $m->id, 'kind' => 'general', 'suggested_value' => 'جستجو', 'close_original' => 1])->assertRedirect();
        $this->assertSame('converted', $guest->fresh()->status);
        $this->assertSame(1, FieldSuggestion::where('suggestable_id', $m->id)->where('suggestable_type', Manuscript::class)->count());

        $this->actingAs($user)->postJson('/feedback', ['category' => 'other', 'message' => 'نامربوط با تصویر', 'files' => [$shot()]])->assertOk();
        $junk = FieldSuggestion::where('kind', 'feedback')->where('status', 'pending')->latest('id')->first();
        $path = $junk->payload['files'][0];
        $this->actingAs($this->admin)->post("/admin/suggestions/{$junk->id}/reject", []);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($path);

        FieldSuggestion::where('kind', 'feedback')->whereIn('id', [$guest->id, $mine->id, $junk->id])->delete();
        $user->delete();
    }

    public function test_people_connected_with_a_manuscript_in_other_roles()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $owner = $this->person('محمدحسین تبریزی');
        $m = $this->manuscript();
        $this->get(route('manuscripts.show', $m))->assertOk()->assertSee('اشخاص مرتبط با نسخه')->assertSee('افزودن مالک، واقف، مصحح');

        // A role is required, and a person from the list or a name
        $this->relate($m, 'person_role', null, ['role' => 'owner'])->assertStatus(422)->assertJsonValidationErrors('role_name');
        $this->relate($m, 'person_role', $owner, ['role' => 'king'])->assertStatus(422)->assertJsonValidationErrors('role');
        $this->relate($m, 'person_role', $owner, ['role' => 'owner', 'suggested_value' => 'مهر بیضی ۱۲۴۰ق در برگ نخست', 'credit_name' => 'پژوهشگر آزمون'])->assertOk();
        $linked = $this->pendingFor($m);
        $this->relate($m, 'person_role', null, ['role' => 'endower', 'role_name' => 'حاجیه خانم ناشناس'])->assertOk();
        $named = $this->pendingFor($m);

        $this->actingAs($this->admin)->get("/admin/suggestions/{$linked->id}")->assertOk()->assertSee('اشخاص مرتبط با نسخه');
        $this->approve($linked, ['role' => 'owner', 'role_link' => 'target', 'contributor_note' => 'مهر بیضی ۱۲۴۰ق در برگ نخست'])->assertRedirect('/admin/suggestions');
        $this->approve($named, ['role' => 'endower', 'role_link' => 'name', 'role_name' => 'حاجیه خانم ناشناس'])->assertRedirect('/admin/suggestions');

        $this->get(route('manuscripts.show', $m))->assertOk()
            ->assertSee('مالک')->assertSee('محمدحسین تبریزی')->assertSee('مهر بیضی ۱۲۴۰ق در برگ نخست')->assertSee('به پیشنهاد پژوهشگر آزمون')
            ->assertSee('واقف')->assertSee('حاجیه خانم ناشناس');
        $this->get(route('people.show', $owner))->assertOk()->assertSee('نقش‌های دیگر در نسخه‌ها')->assertSee('مالک');

        // The same person in the same role twice is refused; a rollback removes the row
        $this->relate($m, 'person_role', $owner, ['role' => 'owner'])->assertOk();
        $this->approve($this->pendingFor($m), ['role' => 'owner', 'role_link' => 'target'])->assertSessionHasErrors('applied_value');
        // Rolling back the latest approval (the endower) leaves the owner
        $this->rollbackLatest($m);
        $this->assertSame([$owner->name], \App\Models\ManuscriptPerson::where('manuscript_id', $m->id)->get()->map->display_name->all());
        $this->assertSame('pending', $named->fresh()->status);

        // Rows follow a merged manuscript
        $dup = $this->manuscript();
        \App\Models\ManuscriptPerson::create(['manuscript_id' => $dup->id, 'person_id' => $owner->id, 'role' => 'glossator']);
        app(\App\Suggestions\RecordMerger::class)->mergeManuscript($dup, $m);
        $this->assertSame(2, \App\Models\ManuscriptPerson::where('manuscript_id', $m->id)->count());
        $this->removedManuscripts[] = $dup->id;
    }

    public function test_manuscript_roles_follow_a_person_merge_and_its_rollback()
    {
        $source = $this->person('مالک تکراری');
        $target = $this->person('مالک اصلی');
        $m = $this->manuscript();
        $row = \App\Models\ManuscriptPerson::create(['manuscript_id' => $m->id, 'person_id' => $source->id, 'role' => 'owner']);

        $log = app(\App\Services\PersonMerger::class)->merge($source, $target, $this->admin->id);
        $this->assertSame($target->id, $row->fresh()->person_id);

        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame($source->id, $row->fresh()->person_id);
        AuditLog::where('auditable_type', Person::class)->whereIn('auditable_id', [$source->id, $target->id])->delete();
    }

    public function test_relations_between_works_and_between_manuscripts()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $matn = $this->work(['primary_title' => 'تهذیب المنطق ' . uniqid()]);
        $sharh = $this->work(['primary_title' => 'الحاشیة علی التهذیب ' . uniqid()]);
        $this->get(route('works.show', $matn))->assertOk()->assertSee('رابطه با آثار دیگر');

        // From the mother text's page: "the chosen work is a gloss on this work"
        $this->relate($matn, 'work_relation', $sharh, ['role' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors('role');
        $this->relate($matn, 'work_relation', $sharh, ['role' => '~gloss'])->assertOk();
        $s = $this->pendingFor($matn);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('نوع رابطه');
        $this->approve($s, ['role' => '~gloss', 'contributor_note' => 'بر بخش منطق'])->assertRedirect('/admin/suggestions');

        $row = DB::table('work_relations')->sole();
        $this->assertSame([$sharh->id, $matn->id, 'gloss'], [$row->work_id, $row->related_work_id, $row->type]);
        $this->get(route('works.show', $matn))->assertSee('حواشی:')->assertSee($sharh->primary_title)->assertSee('بر بخش منطق');
        $this->get(route('works.show', $sharh))->assertSee('حاشیه‌ای است بر:')->assertSee($matn->primary_title);

        // The same relation twice is refused; a rollback removes it
        $this->relate($sharh, 'work_relation', $matn, ['role' => 'gloss'])->assertOk();
        $this->approve($this->pendingFor($sharh), ['role' => 'gloss'])->assertSessionHasErrors('applied_value');
        $this->rollbackLatest($matn);
        $this->assertSame(0, DB::table('work_relations')->count());

        // Manuscripts: «same set» has no direction, and relations follow a merge
        $a = $this->manuscript();
        $b = $this->manuscript();
        $this->relate($a, 'manuscript_relation', $b, ['role' => 'same_set'])->assertOk();
        $this->approve($this->pendingFor($a), ['role' => 'same_set']);
        $this->get(route('manuscripts.show', $b))->assertSee('مجلدهای دیگر از همان دوره');
        $this->relate($b, 'manuscript_relation', $a, ['role' => 'same_set'])->assertOk();
        $this->approve($this->pendingFor($b), ['role' => 'same_set'])->assertSessionHasErrors('applied_value');

        $c = $this->manuscript();
        app(\App\Suggestions\RecordMerger::class)->mergeManuscript($b, $c);
        $this->removedManuscripts[] = $b->id;
        $this->assertSame([$a->id, $c->id], [(int) DB::table('manuscript_relations')->value('manuscript_id'), (int) DB::table('manuscript_relations')->value('related_manuscript_id')]);
        // Merging the two related copies drops the self-relation
        app(\App\Suggestions\RecordMerger::class)->mergeManuscript($c, $a);
        $this->removedManuscripts[] = $c->id;
        $this->assertSame(0, DB::table('manuscript_relations')->count());
    }

    public function test_treatises_bound_in_the_same_volume()
    {
        $this->assertSame(['base' => '513', 'collection' => 'طباطبائی', 'part' => '8'], Manuscript::collectionKey('513/8-طباطبائی'));
        $this->assertSame(['base' => '5722', 'collection' => null, 'part' => '2'], Manuscript::collectionKey('5722/2-29/62'));
        $this->assertNull(Manuscript::collectionKey('5722'));

        $lib = 'کتابخانه‌ی آزمون ' . uniqid();
        $make = fn ($shelfmark) => $this->manuscript(['library' => $lib, 'library_id' => null, 'shelfmark' => $shelfmark, 'shelfmark_key' => $shelfmark, 'folios' => 3]);
        $one = $make('9001/10');
        $make('9001/2');
        $make('9001/1-33/7');
        $make('9001/3-طباطبائی');   // another collection of the library
        $make('90011/1');           // a longer number, not this volume
        $alone = $make('9002/1');

        $this->assertSame(['9001/1-33/7', '9001/2', '9001/10'], $one->collectionSiblings()->pluck('shelfmark')->all());
        $this->get(route('manuscripts.show', $one))->assertOk()->assertSeeInOrder(['رساله‌های این مجموعه', '۳'])->assertSee('(همین نسخه)')->assertDontSee('9001/3-طباطبائی');
        $this->get(route('manuscripts.show', $alone))->assertOk()->assertDontSee('رساله‌های این مجموعه');

        // Volumes of one multi-volume work under one number are shown as such; en dashes read as hyphens
        $this->assertSame(['base' => '111', 'collection' => 'ب', 'part' => '20'], Manuscript::collectionKey('111/20 – ب'));
        $this->assertNull(Manuscript::collectionKey('5679-29/19'));
        $set = $this->work();
        $v1 = $this->manuscript(['library' => $lib, 'library_id' => null, 'shelfmark' => '9003/1', 'shelfmark_key' => '9003/1', 'work_id' => $set->id]);
        $this->manuscript(['library' => $lib, 'library_id' => null, 'shelfmark' => '9003/2', 'shelfmark_key' => '9003/2', 'work_id' => $set->id]);
        $this->get(route('manuscripts.show', $v1))->assertSeeInOrder(['مجلدهای این دوره', '۲'])->assertDontSee('رساله‌های این مجموعه');
    }

    protected function library(string $name): Library
    {
        $library = Library::create(['city' => 'شهر آزمون', 'name' => "{$name} {$this->tag}", 'slug' => 'test-suggest-lib-' . uniqid()]);
        $this->libraryIds[] = $library->id;

        return $library;
    }

    public function test_a_manuscript_moved_to_another_library_or_renumbered()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $from = $this->library('کتابخانه‌ی پیشین');
        $to = $this->library('کتابخانه‌ی تازه');
        $m = $this->manuscript(['library_id' => $from->id, 'library' => $from->name, 'city' => $from->city, 'shelfmark' => '1234', 'shelfmark_key' => '1234']);
        $from->update(['manuscripts_count' => 1]);

        // Something must change; the library picker finds libraries by name
        $this->relate($m, 'relocated', null)->assertStatus(422)->assertJsonValidationErrors('shelfmark');
        $this->relate($m, 'relocated', $from, ['shelfmark' => '1234'])->assertStatus(422)->assertJsonValidationErrors('shelfmark');
        $this->getJson('/api/suggestions/lookup?type=library&q=' . urlencode($to->name))->assertOk()->assertJsonPath('0.id', $to->id);

        $this->relate($m, 'relocated', $to, ['shelfmark' => '77/5'])->assertOk();
        $s = $this->pendingFor($m);
        $this->assertSame(['target_id' => $to->id, 'shelfmark' => '77/5'], $s->payload);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('کتابخانه‌ی پیشنهادی')->assertSee('77/5');
        $this->approve($s, ['library_choice' => 'target', 'shelfmark' => '77/5'])->assertRedirect('/admin/suggestions');

        $m->refresh();
        $this->assertSame([$to->id, $to->name, '77/5', '77/5'], [$m->library_id, $m->library, $m->shelfmark, $m->shelfmark_key]);
        $this->assertSame([0, 1], [$from->fresh()->manuscripts_count, $to->fresh()->manuscripts_count]);
        $this->assertSame([$from->id, $from->name, '1234'], [$m->metadata['former_locations'][0]['library_id'], $m->metadata['former_locations'][0]['library'], $m->metadata['former_locations'][0]['shelfmark']]);
        $this->get(route('manuscripts.show', $m))->assertSee('نشانی پیشین')->assertSee($from->name);

        $this->rollbackLatest($m);
        $m->refresh();
        $this->assertSame([$from->id, '1234', '1234'], [$m->library_id, $m->shelfmark, $m->shelfmark_key]);
        $this->assertArrayNotHasKey('former_locations', $m->metadata ?? []);
        $this->assertSame([1, 0], [$from->fresh()->manuscripts_count, $to->fresh()->manuscripts_count]);
        $this->assertSame('pending', $s->fresh()->status);

        // Only the number changed, or a library fahares does not list yet; the rollback removes that library
        $this->approve($s->fresh(), ['library_choice' => 'keep', 'shelfmark' => '1234'])->assertSessionHasErrors('applied_value');
        $this->approve($s->fresh(), ['library_choice' => 'new', 'library_name' => "کتابخانه‌ی نوساخته {$this->tag}", 'library_city' => 'شهر آزمون', 'shelfmark' => '9/1']);
        $created = Library::where('name', "کتابخانه‌ی نوساخته {$this->tag}")->sole();
        $this->libraryIds[] = $created->id;
        $this->assertSame([$created->id, 1], [$m->fresh()->library_id, $created->fresh()->manuscripts_count]);
        $this->rollbackLatest($m);
        $this->assertNull(Library::find($created->id));
        $this->assertSame($from->id, $m->fresh()->library_id);
    }

    public function test_a_duplicate_library_is_merged_and_the_merge_undone()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $kept = $this->library('کتابخانه‌ی ماندنی');
        $dup = $this->library('کتابخانه‌ی تکراری');
        $dup->update(['full_name' => "نام رسمی تکراری {$this->tag}", 'description' => 'معرفی']);
        $m = $this->manuscript(['library_id' => $dup->id, 'library' => $dup->name, 'city' => 'شهر قدیم', 'shelfmark' => '12']);
        $moved = $this->manuscript(['library_id' => $kept->id, 'library' => $kept->name, 'city' => $kept->city, 'metadata' => ['former_locations' => [['library_id' => $dup->id, 'library' => $dup->name, 'shelfmark' => '3']]]]);
        $volume = \App\Models\CatalogVolume::create(['title' => "فهرست آزمون {$this->tag}", 'library_id' => $dup->id]);
        $this->suggest($dup, ['field_name' => 'country', 'suggested_value' => 'عراق', 'rationale_citation' => 'فهرست'])->assertOk();
        $open = $this->pendingFor($dup);

        $this->relate($dup, 'duplicate', $kept)->assertOk();
        $s = $this->pendingFor($dup);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('کتابخانه‌ی مقصد')->assertSee('کدام کتابخانه بماند؟');
        $this->approve($s, ['keep' => 'target'])->assertRedirect('/admin/suggestions');

        $this->assertNull(Library::find($dup->id));
        $this->assertSame($kept->id, EntityRedirect::resolveTargetId('libraries', $dup->id));
        $this->get("/l/{$dup->id}")->assertRedirect();
        $this->get(route('libraries.show', ['id' => $dup->id]))->assertRedirect(route('libraries.show', $kept->fresh()));
        $m->refresh();
        $this->assertSame([$kept->id, $kept->name, $kept->city], [$m->library_id, $m->library, $m->city]);
        $this->assertSame($kept->id, $volume->fresh()->library_id);
        $this->assertSame($kept->id, $moved->fresh()->metadata['former_locations'][0]['library_id']);
        $this->assertSame($kept->id, $open->fresh()->suggestable_id, 'open suggestions follow the kept library');
        $kept->refresh();
        $this->assertSame([$dup->name, $dup->full_name], $kept->aliases);
        $this->assertSame([$dup->full_name, 'معرفی', 2], [$kept->full_name, $kept->description, $kept->manuscripts_count]);
        $this->get(route('libraries.show', $kept))->assertSee('title="نام دیگر"', false)->assertSee($dup->name);
        $this->get('/libraries?q=' . urlencode($dup->name))->assertSee($kept->name);
        $this->getJson('/api/suggestions/lookup?type=library&q=' . urlencode($dup->name))->assertJsonPath('0.id', $kept->id);

        $log = AuditLog::where('auditable_type', Library::class)->where('auditable_id', $kept->id)->where('action', 'merge')->sole();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');

        $restored = Library::findOrFail($dup->id);
        $this->assertSame([$dup->name, $dup->full_name], [$restored->name, $restored->full_name]);
        $this->assertNull(EntityRedirect::resolveTargetId('libraries', $dup->id));
        $m->refresh();
        $this->assertSame([$dup->id, $dup->name, 'شهر قدیم'], [$m->library_id, $m->library, $m->city]);
        $this->assertSame($dup->id, $volume->fresh()->library_id);
        $this->assertSame($dup->id, $moved->fresh()->metadata['former_locations'][0]['library_id']);
        $this->assertSame($dup->id, $open->fresh()->suggestable_id);
        $kept->refresh();
        $this->assertSame([null, null, null, 1], [$kept->aliases, $kept->full_name, $kept->description, $kept->manuscripts_count]);
        $this->assertSame(1, $restored->manuscripts_count);
        $this->assertSame('pending', $s->fresh()->status);
        $volume->delete();
    }

    public function test_a_renamed_library_keeps_its_former_name()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $library = $this->library('کتابخانه‌ی نام قدیم');
        $other = $this->library('کتابخانه‌ی همسایه');
        $m = $this->manuscript(['library_id' => $library->id, 'library' => $library->name, 'city' => $library->city]);
        $former = $library->name;
        $new = "کتابخانه‌ی نام تازه {$this->tag}";

        $this->relate($library, 'renamed', null)->assertStatus(422)->assertJsonValidationErrors('suggested_value');
        $this->relate($library, 'renamed', null, ['suggested_value' => $former])->assertStatus(422)->assertJsonValidationErrors('suggested_value');
        $this->relate($library, 'renamed', null, ['suggested_value' => $new])->assertOk();
        $s = $this->pendingFor($library);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('نام تازه:');

        // Another library of the same city already has that name: a merge, not a rename
        $this->approve($s, ['name' => $other->name, 'keep_former_name' => '1'])->assertSessionHasErrors('applied_value');
        $this->approve($s, ['name' => $new, 'keep_former_name' => '1'])->assertRedirect('/admin/suggestions');

        $library->refresh();
        $this->assertSame([$new, [$former]], [$library->name, $library->aliases]);
        $this->assertSame($new, $m->fresh()->library);

        $this->rollbackLatest($library);
        $library->refresh();
        $this->assertSame([$former, null], [$library->name, $library->aliases]);
        $this->assertSame($former, $m->fresh()->library);
        $this->assertSame('pending', $s->fresh()->status);

        // Only a misspelling: the former name is not kept
        $this->approve($s->fresh(), ['name' => $new, 'keep_former_name' => '0']);
        $this->assertSame([$new, null], [$library->fresh()->name, $library->fresh()->aliases]);

        // The admin form edits other names and brings the copies on manuscripts in step
        $this->actingAs($this->admin)->put(route('admin.libraries.update', $library), [
            'name' => $former, 'city' => $library->city, 'country' => 'ایران', 'aliases' => "نام یک\n\nنام دو",
        ])->assertRedirect();
        $this->assertSame([$former, ['نام یک', 'نام دو']], [$library->fresh()->name, $library->fresh()->aliases]);
        $this->assertSame($former, $m->fresh()->library);
    }

    public function test_undoing_a_manuscript_merge_restores_the_removed_copy()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $scribe = $this->person('کاتب نسخه‌ی بازگشتی');
        $kept = $this->manuscript(['shelfmark' => '۱۲۳']);
        $dup = $this->manuscript(['copy_date_raw' => '۱۰۹۵ق', 'scribe_id' => $scribe->id, 'scribe_name' => $scribe->name]);
        $third = $this->manuscript();
        $this->removedManuscripts[] = $dup->id;
        $scriptId = DB::table('scripts')->insertGetId(['name' => "خط آزمون {$this->tag}", 'slug' => 'test-script-' . uniqid()]);
        DB::table('manuscript_script')->insert(['manuscript_id' => $dup->id, 'script_id' => $scriptId]);
        $owner = DB::table('manuscript_people')->insertGetId(['manuscript_id' => $dup->id, 'name' => 'مالک', 'role' => 'owner']);
        $relation = DB::table('manuscript_relations')->insertGetId(['manuscript_id' => $dup->id, 'related_manuscript_id' => $third->id, 'type' => 'copied_from']);
        $citation = $dup->sourceCitations()->create(['catalog_id' => \App\Models\Catalog::where('code', 'fankha')->value('id'), 'volume_number' => 9, 'page_start' => 9, 'role' => 'mention']);
        EntityRedirect::recordRedirect('manuscripts', 999999001, $dup->id);
        $this->suggest($dup, ['field_name' => 'folios', 'suggested_value' => '۱۰', 'rationale_citation' => 'شمارش'])->assertOk();
        $open = $this->pendingFor($dup);
        $before = $dup->fresh()->getAttributes();

        $this->relate($dup, 'duplicate', $kept)->assertOk();
        $s = $this->pendingFor($dup);
        $this->approve($s)->assertRedirect('/admin/suggestions');
        $this->assertSame([$kept->id, 1], [DB::table('manuscript_relations')->where('id', $relation)->value('manuscript_id'), $scribe->fresh()->manuscripts_count]);
        $this->assertSame($kept->id, EntityRedirect::resolveTargetId('manuscripts', 999999001));

        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $kept->id)->where('action', 'merge')->sole();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');

        $restored = Manuscript::findOrFail($dup->id);
        $this->assertSame(array_diff_key($before, ['updated_at' => 1]), array_diff_key($restored->getAttributes(), ['updated_at' => 1]));
        $kept->refresh();
        $this->assertSame([null, null, null], [$kept->copy_date_raw, $kept->copy_date_hijri_year, $kept->scribe_id]);
        $this->assertSame(0, DB::table('manuscript_scribe')->where('manuscript_id', $kept->id)->count());
        $this->assertSame([$dup->id], DB::table('manuscript_scribe')->where('person_id', $scribe->id)->pluck('manuscript_id')->all());
        $this->assertTrue(DB::table('manuscript_script')->where(['manuscript_id' => $dup->id, 'script_id' => $scriptId])->exists());
        $this->assertSame($dup->id, DB::table('manuscript_people')->where('id', $owner)->value('manuscript_id'));
        $this->assertSame($dup->id, DB::table('manuscript_relations')->where('id', $relation)->value('manuscript_id'));
        $this->assertSame($dup->id, $citation->fresh()->citable_id);
        $this->assertSame(0, $kept->sourceCitations()->count(), 'the citation the merge added goes');
        $this->assertSame($dup->id, $open->fresh()->suggestable_id);
        $this->assertNull(EntityRedirect::resolveTargetId('manuscripts', $dup->id));
        $this->assertSame($dup->id, EntityRedirect::resolveTargetId('manuscripts', 999999001));
        $this->assertSame(1, $scribe->fresh()->manuscripts_count);
        $this->assertSame(1, $dup->work->fresh()->manuscripts_count);
        $this->assertSame('pending', $s->fresh()->status);
        $this->get(route('manuscripts.show', $restored))->assertOk();

        // A second undo of the same log finds the record already back
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('error');
        EntityRedirect::where('source_id', 999999001)->delete();
        DB::table('scripts')->where('id', $scriptId)->delete();
    }

    public function test_undoing_a_work_merge_restores_the_work_and_its_copies()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $kept = $this->work(['primary_title' => 'عنوان ماندنی ' . $this->tag]);
        $dup = $this->work(['primary_title' => 'عنوان تکراری ' . $this->tag, 'composition_date_raw' => '۹۰۰ق', 'alternative_titles' => [['title' => 'عنوان دوم ' . $this->tag]]]);
        $same = $this->manuscript(['work_id' => $kept->id, 'city' => 'قم', 'library' => 'مرعشی', 'shelfmark' => '۵۵']);
        $copy = $this->manuscript(['work_id' => $dup->id, 'city' => 'قم', 'library' => 'مرعشی', 'shelfmark' => '۵۵', 'folios' => 80]);
        $other = $this->manuscript(['work_id' => $dup->id, 'city' => 'قم', 'library' => 'مرعشی', 'shelfmark' => '۵۶']);
        $this->removedManuscripts[] = $copy->id;
        $kept->update(['manuscripts_count' => 1]);
        $dup->update(['manuscripts_count' => 2]);
        $author = $this->person('پدیدآور اثر تکراری');
        DB::table('work_author')->insert(['work_id' => $dup->id, 'person_id' => $author->id, 'position' => 0]);
        $subject = \App\Models\Subject::create(['name' => "موضوع آزمون {$this->tag}", 'slug' => 'test-subj-' . uniqid()]);
        $dup->subjects()->attach($subject->id);
        $referral = DB::table('referrals')->insertGetId(['source_title' => 'الف', 'target_title' => 'ب', 'target_work_id' => $dup->id, 'volume_number' => 1, 'page' => 1]);
        $before = $dup->fresh()->getAttributes();

        $this->relate($kept, 'duplicate', $dup)->assertOk();
        $s = $this->pendingFor($kept);
        $this->approve($s, ['keep' => 'source'])->assertRedirect('/admin/suggestions');
        $this->assertSame($kept->id, DB::table('referrals')->where('id', $referral)->value('target_work_id'), 'cross-references follow the kept work');
        $this->assertTrue($kept->subjects()->whereKey($subject->id)->exists());
        $this->assertSame(80, $same->fresh()->folios);

        $log = AuditLog::where('auditable_type', Work::class)->where('auditable_id', $kept->id)->where('action', 'merge')->sole();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');

        $restored = Work::findOrFail($dup->id);
        $this->assertSame(array_diff_key($before, ['updated_at' => 1]), array_diff_key($restored->getAttributes(), ['updated_at' => 1]));
        $this->assertSame([$dup->id, $dup->id], [$other->fresh()->work_id, Manuscript::findOrFail($copy->id)->work_id]);
        $this->assertSame([2, 1], [$restored->manuscripts_count, $kept->fresh()->manuscripts_count]);
        $this->assertNull($same->fresh()->folios);
        $kept->refresh();
        $this->assertSame([null, null, null], [$kept->alternative_titles, $kept->composition_date_raw, $kept->composition_year_hijri]);
        $this->assertFalse($kept->subjects()->whereKey($subject->id)->exists());
        $this->assertTrue($restored->subjects()->whereKey($subject->id)->exists());
        $this->assertSame([$dup->id], DB::table('work_author')->where('person_id', $author->id)->pluck('work_id')->all());
        $this->assertSame($dup->id, DB::table('referrals')->where('id', $referral)->value('target_work_id'));
        $this->assertNull(EntityRedirect::resolveTargetId('works', $dup->id));
        $this->assertNull(EntityRedirect::resolveTargetId('manuscripts', $copy->id));
        $this->assertSame('pending', $s->fresh()->status);
        $this->get(route('works.show', $restored))->assertOk();
        DB::table('referrals')->where('id', $referral)->delete();
        $subject->delete();
    }

    protected function rollbackRemoval($record): void
    {
        $log = AuditLog::where('auditable_type', $record::class)->where('auditable_id', $record->id)->where('action', 'remove')->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
    }

    public function test_a_redundant_manuscript_is_removed_and_restored()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $scribe = $this->person('کاتب رکورد زائد');
        $m = $this->manuscript(['shelfmark' => 'زائد', 'scribe_id' => $scribe->id, 'scribe_name' => $scribe->name]);
        $other = $this->manuscript(['work_id' => $m->work_id]);
        $this->removedManuscripts[] = $m->id;
        $work = $m->work;
        $work->update(['manuscripts_count' => 2]);
        $relation = DB::table('manuscript_relations')->insertGetId(['manuscript_id' => $other->id, 'related_manuscript_id' => $m->id, 'type' => 'collated_with']);
        $before = $m->fresh()->getAttributes();

        // No text is needed beyond the explanation of the source
        $this->relate($m, 'redundant', null, ['rationale_citation' => ''])->assertStatus(422)->assertJsonValidationErrors('rationale_citation');
        $this->relate($m, 'redundant', null)->assertOk();
        $s = $this->pendingFor($m);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('حذف رکورد زائد');
        $this->approve($s)->assertRedirect('/admin/suggestions');

        $this->assertNull(Manuscript::find($m->id));
        $this->assertSame(1, $work->fresh()->manuscripts_count);
        $this->assertSame(0, $scribe->fresh()->manuscripts_count);
        $this->assertFalse(DB::table('manuscript_relations')->where('id', $relation)->exists());
        $this->get(route('manuscripts.show', ['id' => $m->id]))->assertRedirect(route('works.show', $work->fresh()));
        $this->assertSame(['applied' => true, 'removed_id' => $m->id], $s->fresh()->resolution);

        $this->rollbackRemoval($m);
        $restored = Manuscript::findOrFail($m->id);
        $this->assertSame(array_diff_key($before, ['updated_at' => 1]), array_diff_key($restored->getAttributes(), ['updated_at' => 1]));
        $this->assertSame([2, 1], [$work->fresh()->manuscripts_count, $scribe->fresh()->manuscripts_count]);
        $this->assertTrue(DB::table('manuscript_relations')->where('id', $relation)->exists());
        $this->assertSame('pending', $s->fresh()->status);
        $this->get(route('manuscripts.show', $restored))->assertOk();
    }

    public function test_a_redundant_work_or_person_goes_only_once_nothing_depends_on_it()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $work = $this->work(['primary_title' => 'سرعنوان زائد ' . $this->tag]);
        $m = $this->manuscript(['work_id' => $work->id]);
        $person = $this->person('عبارت زائد');
        $work->update(['author_id' => $person->id, 'author_name' => $person->name]);
        $referral = DB::table('referrals')->insertGetId(['source_title' => 'الف', 'target_title' => 'ب', 'target_work_id' => $work->id, 'volume_number' => 1, 'page' => 1]);

        $this->relate($work, 'redundant', null)->assertOk();
        $ws = $this->pendingFor($work);
        $this->relate($person, 'redundant', null)->assertOk();
        $ps = $this->pendingFor($person);

        // Still linked: the reviewer sees what blocks it and approval is refused
        $this->actingAs($this->admin)->get("/admin/suggestions/{$ws->id}")->assertOk()->assertSee('۱ نسخه پیوند دارد', false);
        $this->approve($ws)->assertSessionHasErrors('applied_value');
        $this->actingAs($this->admin)->get("/admin/suggestions/{$ps->id}")->assertOk()->assertSee('۱ اثر');
        $this->approve($ps)->assertSessionHasErrors('applied_value');
        $this->assertNotNull(Work::find($work->id));

        // Once its copy is moved elsewhere, the work goes; its author then has nothing left and goes too
        $m->update(['work_id' => $this->work()->id]);
        $this->approve($ws)->assertRedirect('/admin/suggestions');
        $this->assertNull(Work::find($work->id));
        $this->assertNull(DB::table('referrals')->where('id', $referral)->value('target_work_id'));
        $this->get(route('works.show', ['id' => $work->id]))->assertStatus(410)->assertSee('این اثر حذف شده است')->assertSee($work->primary_title);
        $this->approve($ps)->assertRedirect('/admin/suggestions');
        $this->assertNull(Person::find($person->id));
        $this->get(route('people.show', ['id' => $person->id]))->assertStatus(410)->assertSee('این شخص حذف شده است');

        // The work cannot come back before its author, whose link it restores
        $log = AuditLog::where('auditable_type', Work::class)->where('auditable_id', $work->id)->where('action', 'remove')->sole();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('error');
        $this->rollbackRemoval($person);
        $this->rollbackRemoval($work);
        $this->assertSame($person->id, Work::findOrFail($work->id)->author_id);
        $this->assertSame([$work->id], DB::table('work_author')->where('person_id', $person->id)->pluck('work_id')->all());
        $this->assertSame($work->id, DB::table('referrals')->where('id', $referral)->value('target_work_id'));
        $this->assertSame(1, Person::find($person->id)->works_count);
        $this->assertSame(['pending', 'pending'], [$ws->fresh()->status, $ps->fresh()->status]);
        $this->get(route('works.show', Work::find($work->id)))->assertOk();
        DB::table('referrals')->where('id', $referral)->delete();
    }

    protected function rollbackSplit($record): void
    {
        $log = AuditLog::where('auditable_type', $record::class)->where('auditable_id', $record->id)->where('action', 'split')->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
    }

    public function test_a_conflated_work_is_split_and_the_split_undone()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $author = $this->person('پدیدآور اثر خلط‌شده');
        $work = $this->work(['primary_title' => 'اثر خلط‌شده ' . $this->tag, 'author_id' => $author->id, 'author_name' => $author->name]);
        $subject = \App\Models\Subject::create(['name' => "موضوع تفکیک {$this->tag}", 'slug' => 'test-subj-' . uniqid()]);
        $work->subjects()->attach($subject->id);
        $stay = $this->manuscript(['work_id' => $work->id]);
        $go = $this->manuscript(['work_id' => $work->id, 'shelfmark' => 'دیگر']);
        $work->update(['manuscripts_count' => 2]);

        $this->relate($work, 'conflated', null)->assertStatus(422)->assertJsonValidationErrors('suggested_value');
        $this->relate($work, 'conflated', null, ['suggested_value' => 'نسخه‌ی «دیگر» رساله‌ی دیگری است'])->assertOk();
        $s = $this->pendingFor($work);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('عنوان اثر تازه')->assertSee('نسخه‌هایی که از اثر تازه‌اند');

        $this->approve($s, ['new_title' => 'رساله‌ی جدا ' . $this->tag])->assertSessionHasErrors('applied_value');
        $this->approve($s, ['new_title' => 'رساله‌ی جدا ' . $this->tag, 'author' => 'same', 'copy_subjects' => '1', 'manuscript_ids' => [$go->id]])->assertRedirect('/admin/suggestions');

        $created = Work::where('primary_title', 'رساله‌ی جدا ' . $this->tag)->sole();
        $this->works[] = $created;
        $this->assertSame([$created->id, $work->id], [$go->fresh()->work_id, $stay->fresh()->work_id]);
        $this->assertSame([1, 1], [$work->fresh()->manuscripts_count, $created->manuscripts_count]);
        $this->assertSame([$author->id, $work->volume_number, $work->page_start], [$created->author_id, $created->volume_number, $created->page_start]);
        $this->assertTrue($created->subjects()->whereKey($subject->id)->exists());
        $this->assertSame(2, $author->fresh()->works_count);
        $this->assertSame(['applied' => true, 'created_id' => $created->id], $s->fresh()->resolution);
        $this->get(route('works.show', $created))->assertOk();

        $this->rollbackSplit($work);
        $this->assertNull(Work::find($created->id));
        $this->assertSame([$work->id, 2], [$go->fresh()->work_id, $work->fresh()->manuscripts_count]);
        $this->assertSame(1, $author->fresh()->works_count);
        $this->assertSame(1, $subject->fresh()->works_count);
        $this->assertSame('pending', $s->fresh()->status);
        $subject->delete();
    }

    public function test_a_conflated_person_is_split_and_the_split_undone()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $person = $this->person('شخص خلط‌شده');
        $w1 = $this->work(['author_id' => $person->id, 'author_name' => $person->name]);
        $w2 = $this->work(['author_id' => $person->id, 'author_name' => $person->name]);
        $m1 = $this->manuscript(['scribe_id' => $person->id, 'scribe_name' => $person->name]);
        $m2 = $this->manuscript(['scribe_id' => $person->id, 'scribe_name' => $person->name]);
        $role = DB::table('manuscript_people')->insertGetId(['manuscript_id' => $m1->id, 'person_id' => $person->id, 'role' => 'owner']);
        $person->recountContributions();
        $this->assertSame([2, 2], [$person->fresh()->works_count, $person->fresh()->manuscripts_count]);

        $this->relate($person, 'conflated', null, ['suggested_value' => 'کاتب نسخه‌ی دوم هم‌نام اوست'])->assertOk();
        $s = $this->pendingFor($person);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('آثاری که از شخص تازه‌اند')->assertSee('نقش‌های دیگری که از شخص تازه‌اند');
        $this->approve($s, ['new_name' => 'هم‌نام ' . $this->tag])->assertSessionHasErrors('applied_value');
        $this->approve($s, ['new_name' => 'هم‌نام ' . $this->tag, 'death_year_hijri' => 1100, 'work_ids' => [$w2->id], 'manuscript_ids' => [$m2->id], 'role_ids' => [$role]])->assertRedirect('/admin/suggestions');

        $created = Person::where('name', 'هم‌نام ' . $this->tag)->sole();
        $this->assertSame([$person->id, $created->id], [$w1->fresh()->author_id, $w2->fresh()->author_id]);
        $this->assertSame([$person->id, $created->id], [$m1->fresh()->scribe_id, $m2->fresh()->scribe_id]);
        $this->assertSame([$w2->id], DB::table('work_author')->where('person_id', $created->id)->pluck('work_id')->all());
        $this->assertSame([$m2->id], DB::table('manuscript_scribe')->where('person_id', $created->id)->pluck('manuscript_id')->all());
        $this->assertSame($created->id, DB::table('manuscript_people')->where('id', $role)->value('person_id'));
        $this->assertSame([1, 1, 1100], [$created->works_count, $created->manuscripts_count, $created->death_year_hijri]);
        $this->assertSame([1, 1], [$person->fresh()->works_count, $person->fresh()->manuscripts_count]);
        $this->get(route('people.show', $created))->assertOk();

        $this->rollbackSplit($person);
        $this->assertNull(Person::find($created->id));
        $this->assertSame([$person->id, $person->id], [$w2->fresh()->author_id, $m2->fresh()->scribe_id]);
        $this->assertSame($person->id, DB::table('manuscript_people')->where('id', $role)->value('person_id'));
        $this->assertSame([2, 2], [$person->fresh()->works_count, $person->fresh()->manuscripts_count]);
        $this->assertSame('pending', $s->fresh()->status);
    }
}
