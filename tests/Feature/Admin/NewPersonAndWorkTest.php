<?php

namespace Tests\Feature\Admin;

use App\Auth\Permissions;
use App\Models\AuditLog;
use App\Models\Language;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Subject;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «شخص تازه» and «اثر تازه» (backlog item 161, step 14.381): a person's and a work's record made from the panel, the
 * namesakes and same-titled works shown before, the person a work or copy form makes for a name the site lacks, and
 * their undo from the audit log.
 */
class NewPersonAndWorkTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permissions::flush();
        config(['scout.driver' => 'collection']);
        $this->admin = User::create(['name' => 'مدیر کل', 'email' => uniqid('npw-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
    }

    private function person(string $name, array $attributes = []): Person
    {
        return Person::create($attributes + ['name' => $name, 'normalized_name' => $name, 'slug' => 'npw-p-' . uniqid()]);
    }

    private function rollback(Person|Work|Manuscript $record)
    {
        $log = AuditLog::where('auditable_type', $record::class)->where('auditable_id', $record->id)->where('action', 'create')->firstOrFail();

        return $this->actingAs($this->admin)->post(route('admin.audit-logs.rollback', $log));
    }

    public function test_the_forms_need_their_permissions()
    {
        $user = User::create(['name' => 'کاربر', 'email' => uniqid('npw-u-') . '@example.test', 'password' => 'password123', 'role' => 'user']);
        $this->actingAs($user)->get(route('admin.people.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.people.store'), ['name' => 'شخص آزمون'])->assertForbidden();
        // an editor edits people, so may add one; a new work goes with the right to make copies
        $editor = User::create(['name' => 'ویراستار', 'email' => uniqid('npw-ed-') . '@example.test', 'password' => 'password123', 'role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.people.create'))->assertOk()->assertSee('شخص تازه');
        $this->actingAs($editor)->get(route('admin.works.create'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.works.create'))->assertOk()->assertSee('اثر تازه')->assertSee('عناوین دیگر');
        $this->actingAs($this->admin)->get(route('admin.people.index'))->assertOk()->assertSee(route('admin.people.create'), false);
    }

    public function test_a_person_is_made_with_the_fields_of_the_edit_form_a_card_of_its_source_and_an_undo()
    {
        $source = Source::create(['code' => 'npw-src-' . uniqid(), 'title' => 'ریحانة الادب آزمون', 'kind' => Source::KIND_LIBRARY_CATALOGUE]);

        $this->actingAs($this->admin)->post(route('admin.people.store'), [
            'name' => 'میرزا ابوالحسن  جلوه', 'aliases' => "جلوه | jelve\nابوالحسن طباطبایی", 'wrong_names' => 'جلوة',
            'birth_year_hijri' => 1238, 'death_year_hijri' => 1314, 'bio_notes' => 'حکیم', 'transliteration' => 'Mīrzā Abu-l-Ḥasan Jelve',
            'source_id' => $source->id, 'source_place' => 'ج ۱، ص ۴۳۰', 'source_text' => 'به گزارش آقای آزمون',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');

        $person = Person::latest('id')->firstOrFail();
        $this->assertSame(['میرزا ابوالحسن جلوه', ['جلوه', 'ابوالحسن طباطبایی'], ['جلوه' => 'jelve'], ['جلوة']], [$person->name, $person->aliases, $person->alias_transliterations, $person->wrong_names]);
        // the century follows the death year, as in the edit form; the person has no works yet
        $this->assertSame([1238, 1314, 14, 0, false], [$person->birth_year_hijri, $person->death_year_hijri, $person->century_hijri, (int) $person->works_count, (bool) $person->is_author]);
        $this->assertStringEndsWith('-' . $person->id, $person->slug);

        $entry = SourceEntry::where('citable_type', Person::class)->where('citable_id', $person->id)->firstOrFail();
        $this->assertSame([$source->id, 1, 430, SourceEntry::ORIGIN_MANUAL, SourceEntry::STATUS_VERIFIED], [$entry->source_id, $entry->volume_number, $entry->page_start, $entry->origin, $entry->status]);
        $log = AuditLog::where('auditable_type', Person::class)->where('auditable_id', $person->id)->where('action', 'create')->firstOrFail();
        $this->assertTrue($log->new_values['panel']);
        $this->assertSame('به گزارش آقای آزمون', $log->new_values['source_text']);

        $this->actingAs($this->admin)->get(route('people.show', $person))->assertOk()->assertSee('میرزا ابوالحسن جلوه');

        // undone: the person and its card go
        $this->rollback($person)->assertSessionHas('success');
        $this->assertNull(Person::find($person->id));
        $this->assertSame(0, SourceEntry::where('citable_type', Person::class)->where('citable_id', $person->id)->count());
        $this->assertSame('rollback_create', AuditLog::latest('id')->first()->action);
    }

    public function test_a_namesake_is_shown_first_and_the_person_is_made_only_when_told_again()
    {
        $old = $this->person('ملا محمد اسماعیل خواجویی', ['aliases' => ['خواجویی']]);

        // by an other name of the old person, written with the Arabic yeh
        $this->actingAs($this->admin)->from(route('admin.people.create'))->post(route('admin.people.store'), ['name' => 'محمد اسماعیل مازندرانی', 'aliases' => 'خواجوئي'])
            ->assertRedirect(route('admin.people.create'))->assertSessionHas('duplicates', [$old->id]);
        $this->assertSame(0, Person::where('name', 'محمد اسماعیل مازندرانی')->count());
        $this->actingAs($this->admin)->get(route('admin.people.create'))->assertSee('data-duplicate-warning', false)->assertSee('data-namesake="' . $old->id . '"', false);

        $this->actingAs($this->admin)->post(route('admin.people.store'), ['name' => 'محمد اسماعیل مازندرانی', 'aliases' => 'خواجوئي', 'confirm_duplicate' => 1])
            ->assertSessionHas('success');
        $this->assertSame(1, Person::where('name', 'محمد اسماعیل مازندرانی')->count());

        $this->actingAs($this->admin)->post(route('admin.people.store'), ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_a_work_is_made_with_its_author_subjects_and_languages_and_undone_while_it_has_no_copy()
    {
        $author = $this->person('شیخ بهایی آزمون', ['death_year_hijri' => 1030]);
        $fiqh = Subject::create(['name' => 'فقه آزمون', 'slug' => 'npw-s-' . uniqid()]);
        $child = Subject::create(['name' => 'قواعد فقه آزمون', 'slug' => 'npw-s-' . uniqid(), 'parent_id' => $fiqh->id]);
        $arabic = Language::create(['name' => 'عربی آزمون', 'slug' => 'npw-l-' . uniqid()]);

        $this->actingAs($this->admin)->post(route('admin.works.store'), [
            'title' => 'الاثنا عشریة', 'alternative_titles' => "اثنا عشریه\nرسالة الصلاة", 'author_id' => $author->id,
            'subject_summary' => 'قواعد فقه آزمون، موضوعی که نیست', 'language_summary' => 'عربی آزمون', 'composition_date_raw' => '1012ق',
            'composition_place' => 'اصفهان', 'incipit_text' => 'الحمد لله', 'explicit_text' => 'تمت',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error')->assertSessionHas('success', fn ($m) => str_contains($m, 'موضوعی که نیست'));

        $work = Work::latest('id')->firstOrFail();
        $this->assertSame(['الاثنا عشریة', $author->id, 'شیخ بهایی آزمون', 1012, 0], [$work->primary_title, $work->author_id, $work->author_name, $work->composition_year_hijri, (int) $work->manuscripts_count]);
        $this->assertSame([['title' => 'اثنا عشریه'], ['title' => 'رسالة الصلاة']], $work->alternative_titles);
        $this->assertNull($work->catalog_id);
        // the author's counts and row, the subject (and its parent) and the language
        $this->assertSame([1, true], [(int) $author->fresh()->works_count, (bool) $author->fresh()->is_author]);
        $this->assertSame([$child->id], $work->subjects()->pluck('subjects.id')->all());
        $this->assertSame([1, 1, 1], [(int) $child->fresh()->works_count, (int) $fiqh->fresh()->works_count, (int) $arabic->fresh()->works_count]);

        $this->actingAs($this->admin)->get(route('works.show', $work))->assertOk()->assertSee('الاثنا عشریة');

        $this->rollback($work)->assertSessionHas('success');
        $this->assertNull(Work::find($work->id));
        $this->assertSame([0, 0, 0], [(int) $author->fresh()->works_count, (int) $child->fresh()->works_count, (int) $arabic->fresh()->works_count]);
        // the author was not made by the form: they stay
        $this->assertNotNull(Person::find($author->id));
    }

    public function test_a_work_of_the_same_title_by_the_same_author_is_shown_first()
    {
        $author = $this->person('فیض کاشانی آزمون');
        $same = Work::create(['primary_title' => 'الوافي', 'clean_title' => 'الوافي', 'slug' => 'npw-w-' . uniqid(), 'author_id' => $author->id, 'alternative_titles' => [['title' => 'وافی']]]);
        Work::create(['primary_title' => 'المحجة', 'clean_title' => 'المحجة', 'slug' => 'npw-w-' . uniqid(), 'author_id' => $author->id]);

        // «وافی» is an other title of the work, written with the Persian yeh
        $this->actingAs($this->admin)->from(route('admin.works.create'))->post(route('admin.works.store'), ['title' => 'وافي', 'author_id' => $author->id])
            ->assertRedirect(route('admin.works.create'))->assertSessionHas('duplicates', [$same->id]);
        $this->actingAs($this->admin)->get(route('admin.works.create'))->assertSee('data-duplicate-work="' . $same->id . '"', false);
        $this->assertSame(2, Work::where('author_id', $author->id)->count());

        // another author's work of that title is no duplicate
        $other = $this->person('مؤلف دیگر آزمون');
        $this->actingAs($this->admin)->post(route('admin.works.store'), ['title' => 'الوافي', 'author_id' => $other->id])->assertSessionHas('success');
        // told again, the same author's is made too
        $this->actingAs($this->admin)->post(route('admin.works.store'), ['title' => 'وافي', 'author_id' => $author->id, 'confirm_duplicate' => 1])->assertSessionHas('success');
        $this->assertSame(3, Work::where('author_id', $author->id)->count());
    }

    public function test_a_work_form_makes_its_author_and_the_undo_takes_both_back()
    {
        $namesake = $this->person('حسن بن علی آزمونی');

        $this->actingAs($this->admin)->from(route('admin.works.create'))->post(route('admin.works.store'), ['title' => 'رسالۀ آزمون', 'author_name' => 'حسن بن علی آزمونی', 'author_new' => 1])
            ->assertRedirect(route('admin.works.create'))->assertSessionHas('namesakes', [$namesake->id]);
        $this->actingAs($this->admin)->post(route('admin.works.store'), ['title' => 'رسالۀ آزمون', 'author_name' => 'حسن بن علی آزمونی', 'author_new' => 1, 'author_death_year' => 950, 'confirm_duplicate' => 1])
            ->assertSessionHas('success');

        $work = Work::latest('id')->firstOrFail();
        $author = $work->author;
        $this->assertNotSame($namesake->id, $author->id);
        $this->assertSame(['حسن بن علی آزمونی', 950, 10, 1], [$author->name, $author->death_year_hijri, $author->century_hijri, (int) $author->works_count]);
        $log = AuditLog::where('auditable_type', Work::class)->where('auditable_id', $work->id)->where('action', 'create')->firstOrFail();
        $this->assertSame([$author->id], $log->old_values['created']['person_ids']);

        // a person of the site and a new one at once is refused
        $this->actingAs($this->admin)->post(route('admin.works.store'), ['title' => 'دیگر', 'author_id' => $namesake->id, 'author_name' => 'کسی', 'author_new' => 1])->assertSessionHas('error');
        // a new person needs a name
        $this->actingAs($this->admin)->post(route('admin.works.store'), ['title' => 'دیگر', 'author_new' => 1])->assertSessionHasErrors('author_name');

        $this->rollback($work)->assertSessionHas('success');
        $this->assertNull(Work::find($work->id));
        $this->assertNull(Person::find($author->id));
        $this->assertNotNull(Person::find($namesake->id));
    }

    public function test_a_work_with_a_copy_is_not_undone()
    {
        $this->actingAs($this->admin)->post(route('admin.works.store'), ['title' => 'اثر بی نسخۀ آزمون'])->assertSessionHas('success');
        $work = Work::latest('id')->firstOrFail();
        $library = Library::create(['name' => 'کتابخانۀ آزمون', 'city' => 'قم', 'slug' => 'npw-lib-' . uniqid()]);
        Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'library_id' => $library->id, 'library' => $library->name, 'shelfmark' => '12']);

        $this->rollback($work)->assertSessionHas('error');
        $this->assertNotNull(Work::find($work->id));
    }

    public function test_the_copy_form_makes_its_author_and_scribe_and_its_undo_takes_them_back()
    {
        $library = Library::create(['name' => 'ملی آزمون', 'city' => 'تهران', 'slug' => 'npw-lib-' . uniqid()]);
        $namesake = $this->person('محمد شفیع کاتب آزمون');
        $post = fn (array $extra = []) => $this->actingAs($this->admin)->from(route('admin.manuscripts.create'))->post(route('admin.manuscripts.store'), $extra + [
            'library_id' => $library->id, 'shelfmark' => '501', 'source_kind' => 'researcher',
            'work_mode' => 'new', 'work_title' => 'رسالۀ تازۀ آزمون', 'author_name' => 'مؤلف تازۀ آزمون', 'author_new' => 1,
            'scribe_name' => 'محمد شفیع کاتب آزمون', 'scribe_new' => 1,
        ]);

        // the scribe's name is a person's of the site: shown first, by role
        $post()->assertRedirect(route('admin.manuscripts.create'))->assertSessionHas('namesakes', ['scribe' => [$namesake->id]]);
        $this->assertSame(0, Manuscript::where('shelfmark', '501')->count());
        $this->actingAs($this->admin)->get(route('admin.manuscripts.create'))->assertSee('data-namesake-warning', false)->assertSee('data-namesake="' . $namesake->id . '"', false);

        $post(['confirm_people' => 1])->assertSessionHas('success');
        $copy = Manuscript::where('shelfmark', '501')->firstOrFail();
        $author = $copy->work->author;
        $scribe = $copy->scribe;
        $this->assertSame(['مؤلف تازۀ آزمون', 1], [$author?->name, (int) $author->works_count]);
        $this->assertSame(['محمد شفیع کاتب آزمون', 1], [$scribe?->name, (int) $scribe->manuscripts_count]);
        $this->assertNotSame($namesake->id, $scribe->id);
        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $copy->id)->where('action', 'create')->firstOrFail();
        $this->assertEqualsCanonicalizing([$author->id, $scribe->id], $log->old_values['created']['person_ids']);

        $this->rollback($copy)->assertSessionHas('success');
        $this->assertNull(Manuscript::find($copy->id));
        $this->assertSame(0, Person::whereIn('id', [$author->id, $scribe->id])->count());
        $this->assertNotNull(Person::find($namesake->id));
    }

    public function test_a_person_made_by_a_form_stays_when_something_else_names_them_by_the_undo()
    {
        $this->actingAs($this->admin)->post(route('admin.works.store'), ['title' => 'اثر یکم آزمون', 'author_name' => 'پدیدآور تنهای آزمون', 'author_new' => 1])->assertSessionHas('success');
        $first = Work::latest('id')->firstOrFail();
        $author = $first->author;
        // a second work of theirs, made later
        $this->actingAs($this->admin)->post(route('admin.works.store'), ['title' => 'اثر دوم آزمون', 'author_id' => $author->id])->assertSessionHas('success');

        $this->rollback($first)->assertSessionHas('success');
        $this->assertNull(Work::find($first->id));
        $this->assertSame(1, (int) $author->fresh()->works_count);
        $this->assertSame(1, DB::table('work_people')->where('person_id', $author->id)->count());
    }
}
