<?php

namespace Tests\Feature\Admin;

use App\Auth\Permissions;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\User;
use App\Models\Work;
use App\Sources\SourceEntrySync;
use App\Support\DerivedData;
use App\Support\ScriptNameCleaner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «نسخۀ تازه» (backlog item 149, step 14.306): a copy's record made from the panel, under a work the site has, a new
 * one or an untitled one; the warning before a second copy of a shelfmark; its undo from the audit log; and the
 * corpus commands, which leave such a record alone.
 */
class NewManuscriptTest extends TestCase
{
    protected User $admin;

    protected Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permissions::flush();
        config(['scout.driver' => 'collection']);
        $this->admin = User::create(['name' => 'مدیر کل', 'email' => uniqid('nm-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->library = Library::create(['name' => 'ملی آزمون', 'city' => 'تهران', 'slug' => 'nm-lib-' . uniqid()]);
    }

    private function work(array $attributes = []): Work
    {
        return Work::create($attributes + ['primary_title' => 'جوهریه سیمی', 'clean_title' => 'جوهریه سیمی', 'slug' => 'nm-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function copy(array $attributes = []): Manuscript
    {
        return Manuscript::create($attributes + ['work_id' => $attributes['work_id'] ?? $this->work()->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1,
            'library_id' => $this->library->id, 'library' => $this->library->name, 'city' => $this->library->city]);
    }

    private function store(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('admin.manuscripts.store'), $data + [
            'library_id' => $this->library->id, 'work_mode' => 'untitled', 'source_kind' => 'researcher',
        ]);
    }

    private function created(): Manuscript
    {
        return Manuscript::whereNull('catalog_id')->latest('id')->firstOrFail();
    }

    public function test_only_a_role_with_the_permission_reaches_the_form()
    {
        $editor = User::create(['name' => 'ویراستار', 'email' => uniqid('nm-ed-') . '@example.test', 'password' => 'password123', 'role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.manuscripts.create'))->assertForbidden();
        $this->store(['shelfmark' => '10/1'], $editor)->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.manuscripts.create'))->assertOk()->assertSee('نسخۀ تازه');
    }

    public function test_a_copy_under_an_existing_work_counts_and_is_shown_with_its_source_of_knowledge()
    {
        $work = $this->work();
        $first = $this->copy(['work_id' => $work->id, 'shelfmark' => '3522/1', 'range_from' => '2پ', 'range_to' => '16ر', 'range_from_number' => 2, 'range_to_number' => 16]);
        $scribe = Person::create(['name' => 'کاتب آزمون', 'normalized_name' => 'کاتب آزمون', 'slug' => 'nm-p-' . uniqid()]);
        $suggestion = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $first->id, 'field_name' => 'general',
            'suggested_value' => 'جزء دیگری دارد', 'status' => 'pending', 'kind' => 'general']);

        $this->store([
            'shelfmark' => '۳۵۲۲/۱ مکرر', 'work_mode' => 'existing', 'work_id' => $work->id, 'range' => '۱۶ر تا ۱۶پ', 'folios' => 1,
            'scribe_id' => $scribe->id, 'copy_date_raw' => '1084ق', 'incipit_text' => 'بدان که', 'explicit_text' => 'تمام شد',
            'source_text' => 'خانم نامدار', 'suggestion_id' => $suggestion->id,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');

        $copy = $this->created();
        $this->assertSame([$work->id, null, null, null, 2], [$copy->work_id, $copy->catalog_id, $copy->volume_number, $copy->page_start, $copy->sequence_number]);
        $this->assertSame(['3522/1 مکرر', '3522', '16ر', '16پ', 16, 1], [$copy->shelfmark, $copy->collection_key, $copy->range_from, $copy->range_to, $copy->range_from_number, $copy->folios]);
        $this->assertSame(['ملی آزمون', 'تهران', 'کاتب آزمون', 1084], [$copy->library, $copy->city, $copy->scribe_name, $copy->copy_date_from]);
        $this->assertNull($copy->raw_text);
        // the counts of its work, library and scribe
        $this->assertSame(2, $work->fresh()->manuscripts_count);
        $this->assertSame(Manuscript::where('library_id', $this->library->id)->count(), (int) $this->library->fresh()->manuscripts_count);
        $this->assertSame(1, $scribe->fresh()->manuscripts_count);
        // no entry of Fankha was made for it
        $this->assertSame(0, SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $copy->id)->count());

        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $copy->id)->where('action', 'create')->firstOrFail();
        $this->assertTrue($log->new_values['panel']);
        $this->assertSame($copy->id, $log->old_values['created']['manuscript_id']);
        $this->assertNull($log->old_values['created']['work_id']);

        // its page: where the knowledge came from, with the suggestion for a reviewer only
        $page = $this->actingAs($this->admin)->get(route('manuscripts.show', $copy))->assertOk();
        $page->assertSee('به گزارش خانم نامدار، که نسخه را دیده است')->assertSee('data-provenance-suggestion', false)->assertDontSee('متن مأخذ');
        $this->app['auth']->forgetGuards();
        $this->get(route('manuscripts.show', $copy))->assertOk()->assertSee('به گزارش خانم نامدار')->assertDontSee('data-provenance-suggestion', false)->assertDontSee('data-add-sibling', false);
        // the volume's list names it as added
        $this->get(route('manuscripts.show', $first))->assertOk()->assertSee('data-added-copy', false)->assertSee('افزودۀ پژوهشگر');
    }

    public function test_a_new_work_and_an_untitled_one_are_made_with_the_copy()
    {
        $author = Person::create(['name' => 'مؤلف آزمون', 'normalized_name' => 'مؤلف آزمون', 'slug' => 'nm-a-' . uniqid()]);
        $this->store(['shelfmark' => '2897/3', 'work_mode' => 'new', 'work_title' => 'رساله در خط', 'author_id' => $author->id, 'range' => '7پ-12پ'])->assertRedirect()->assertSessionMissing('error');
        $copy = $this->created();
        $work = $copy->work;
        $this->assertSame(['رساله در خط', $author->id, 'مؤلف آزمون', null, null, 1], [$work->primary_title, $work->author_id, $work->author_name, $work->catalog_id, $work->volume_number, $work->manuscripts_count]);
        $this->assertSame(1, $author->fresh()->works_count);
        $this->assertSame('2897', $copy->collection_key);
        $this->get(route('works.show', $work))->assertOk()->assertSee('رساله در خط');

        $this->store(['shelfmark' => '2897/4', 'range' => '1ر تا 2ر'])->assertRedirect()->assertSessionMissing('error');
        $untitled = $this->created();
        $this->assertSame('بی‌عنوان (ملی آزمون، ش 2897/4)', $untitled->work->primary_title);
        $this->assertNull($untitled->work->author_id);
        $this->get(route('manuscripts.show', $untitled))->assertOk()->assertSee('بر پایۀ دیدن نسخه');
        // both treatises stand in the volume's list, in the order of their leaves
        $this->assertSame([$untitled->id, $copy->id], $copy->collectionSiblings()->pluck('id')->all());
    }

    public function test_a_second_copy_of_a_shelfmark_is_made_only_after_the_warning()
    {
        $existing = $this->copy(['shelfmark' => '2897/1']);
        $before = Manuscript::count();

        $this->from(route('admin.manuscripts.create'))->store(['shelfmark' => '۲۸۹۷/۱'])
            ->assertRedirect(route('admin.manuscripts.create'))->assertSessionHas('duplicates', [$existing->id]);
        $this->assertSame($before, Manuscript::count());
        $this->actingAs($this->admin)->withSession(['duplicates' => [$existing->id]])->get(route('admin.manuscripts.create'))
            ->assertOk()->assertSee('data-duplicate-warning', false)->assertSee('این کتابخانه با همین شماره نسخه دارد');

        $this->store(['shelfmark' => '۲۸۹۷/۱', 'confirm_duplicate' => 1])->assertRedirect()->assertSessionMissing('duplicates');
        $this->assertSame($before + 1, Manuscript::count());
    }

    public function test_another_treatise_of_a_copys_volume_starts_from_its_library_and_number()
    {
        // a library that gave the volume one number: the copy has no volume key yet
        $existing = $this->copy(['shelfmark' => '12422']);
        $this->assertNull($existing->collection_key);

        $this->actingAs($this->admin)->get(route('manuscripts.show', $existing))->assertOk()->assertSee('data-add-sibling', false);
        $form = $this->actingAs($this->admin)->get(route('admin.manuscripts.create', ['from' => $existing->id]))->assertOk();
        $form->assertSee('name="collection_key" id="collection_key" value="12422"', false)->assertSee('name="shelfmark" id="shelfmark" value="12422"', false)->assertSee('ملی آزمون');

        $this->store(['from_id' => $existing->id, 'shelfmark' => '12422', 'collection_key' => '12422', 'confirm_duplicate' => 1, 'range' => '30-41'])->assertRedirect()->assertSessionMissing('error');
        $copy = $this->created();
        $this->assertSame('12422', $copy->collection_key);
        $this->assertSame('12422', $existing->fresh()->collection_key);
        $this->assertEqualsCanonicalizing([$existing->id, $copy->id], $copy->collectionSiblings()->pluck('id')->all());

        // a shelfmark of another volume is refused
        $this->store(['shelfmark' => '500/2', 'collection_key' => '12422'])->assertRedirect()->assertSessionHas('error');

        // the undo takes the key back from the copy it was given to
        $log = AuditLog::where('auditable_id', $copy->id)->where('action', 'create')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.audit-logs.rollback', $log))->assertSessionHas('success');
        $this->assertNull($existing->fresh()->collection_key);
    }

    public function test_a_copy_from_a_printed_catalogue_takes_the_catalogues_card()
    {
        $source = Source::create(['code' => 'nm-src-' . uniqid(), 'title' => 'فهرست ملی آزمون', 'kind' => Source::KIND_LIBRARY_CATALOGUE]);

        $this->store(['shelfmark' => '77', 'source_kind' => 'catalogue', 'source_text' => 'فهرست ملی، ج ۶، ص ۶۰۱', 'source_id' => $source->id, 'source_place' => 'ج ۶، ص ۶۰۱'])
            ->assertRedirect()->assertSessionMissing('error');
        $copy = $this->created();
        $entry = SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $copy->id)->firstOrFail();
        $this->assertSame([$source->id, 6, 601, SourceEntry::ORIGIN_MANUAL, SourceEntry::STATUS_VERIFIED], [$entry->source_id, $entry->volume_number, $entry->page_start, $entry->origin, $entry->status]);
        $this->assertSame('افزوده از فهرست', $copy->provenance()['chip']);
        $this->get(route('manuscripts.show', $copy))->assertOk()->assertSee('فهرست ملی آزمون');

        // a source without its place is refused
        $this->store(['shelfmark' => '78', 'source_kind' => 'catalogue', 'source_id' => $source->id])->assertSessionHasErrors('source_place');
    }

    public function test_the_undo_removes_the_copy_and_the_work_made_for_it()
    {
        $this->store(['shelfmark' => '2897/3', 'work_mode' => 'new', 'work_title' => 'رساله در خط'])->assertRedirect();
        $copy = $this->created();
        $workId = $copy->work_id;
        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $copy->id)->where('action', 'create')->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.audit-logs.rollback', $log))->assertSessionHas('success');
        $this->assertNull(Manuscript::find($copy->id));
        $this->assertNull(Work::find($workId));
        $this->assertSame(0, (int) $this->library->fresh()->manuscripts_count);
        $this->assertTrue($log->fresh()->isRolledBack());
        $this->assertSame('rollback_create', AuditLog::latest('id')->first()->action);
        // not twice
        $this->actingAs($this->admin)->post(route('admin.audit-logs.rollback', $log))->assertSessionHas('error');

        // under a work the site had, the work stays and counts one less
        $work = $this->work();
        $this->copy(['work_id' => $work->id, 'shelfmark' => '5']);
        $this->store(['shelfmark' => '6', 'work_mode' => 'existing', 'work_id' => $work->id])->assertRedirect();
        $second = $this->created();
        $this->assertSame(2, $work->fresh()->manuscripts_count);
        $this->actingAs($this->admin)->post(route('admin.audit-logs.rollback', AuditLog::where('auditable_id', $second->id)->where('action', 'create')->firstOrFail()))->assertSessionHas('success');
        $this->assertSame(1, $work->fresh()->manuscripts_count);
        $this->assertNotNull(Work::find($work->id));

        // a copy of the corpus («create» without the panel's mark) is not undone from here
        $corpus = AuditLog::create(['auditable_type' => Manuscript::class, 'auditable_id' => $this->copy(['shelfmark' => '9'])->id, 'action' => 'create', 'old_values' => ['x' => 1], 'new_values' => ['corpus_fix' => 'f']]);
        $this->actingAs($this->admin)->post(route('admin.audit-logs.rollback', $corpus))->assertSessionHas('error');
    }

    public function test_the_corpus_commands_leave_a_copy_made_in_the_panel_alone()
    {
        $work = $this->work();
        $sibling = $this->copy(['work_id' => $work->id, 'shelfmark' => '12422']);
        $this->store(['from_id' => $sibling->id, 'shelfmark' => '12422', 'collection_key' => '12422', 'confirm_duplicate' => 1, 'work_mode' => 'existing', 'work_id' => $work->id,
            'range' => '16ر تا 16پ', 'folios' => 1, 'copy_date_raw' => 'قرن 12', 'incipit_text' => 'بدان که'])->assertRedirect()->assertSessionMissing('error');
        $copy = $this->created();
        $columns = ['work_id', 'catalog_id', 'volume_number', 'page_start', 'sequence_number', 'shelfmark', 'collection_key', 'folios', 'pages', 'extent_raw',
            'range_from', 'range_to', 'range_from_number', 'range_to_number', 'copy_date_raw', 'copy_date_from', 'copy_date_to', 'incipit_text', 'raw_text', 'metadata'];
        $before = (array) DB::table('manuscripts')->where('id', $copy->id)->first($columns);

        // the derived columns: nothing of it differs from what its own columns give
        DerivedData::syncCopies([$copy->id, $sibling->id]);
        $this->artisan('fahares:refresh-derived', ['--apply' => true, '--show' => 500])->assertSuccessful();
        // its entries in the sources: none of Fankha is made
        SourceEntrySync::syncIds(Manuscript::class, [$copy->id]);
        $this->assertSame($before, (array) DB::table('manuscripts')->where('id', $copy->id)->first($columns));
        $this->assertSame('12422', $sibling->fresh()->collection_key);
        $this->assertSame(0, SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $copy->id)->count());

        // the commands that find a copy by its work, sequence and shelfmark look among Fankha's copies only
        $this->assertSame([$sibling->id], Manuscript::fromFankha()->where('work_id', $work->id)->pluck('id')->all());

        // a decision file of fahares:rebuild-copies that names it is refused
        $file = tempnam(sys_get_temp_dir(), 'nm-rebuild');
        file_put_contents($file, json_encode(['records' => [['table' => 'manuscripts', 'id' => $copy->id, 'shelfmark' => '12422', 'category' => 'rebuild', 'v2' => 'v99-1#1',
            'changes' => ['raw_text' => ['from' => null, 'to' => 'متن دیگر'], 'incipit_text' => ['from' => 'بدان که', 'to' => 'آغاز دیگر']]]]], JSON_UNESCAPED_UNICODE));
        $this->artisan('fahares:rebuild-copies', ['--file' => $file, '--apply' => true])->expectsOutputToContain('not a copy of Fankha')->assertSuccessful();
        @unlink($file);
        $this->assertSame($before, (array) DB::table('manuscripts')->where('id', $copy->id)->first($columns));
    }

    public function test_the_copys_body_scripts_features_and_notes_are_made_with_it_and_go_with_the_undo()
    {
        $this->actingAs($this->admin)->get(route('admin.manuscripts.create'))->assertOk()->assertSee('کتابت و کالبد نسخه')->assertSee('data-add-note', false);

        $this->store([
            'shelfmark' => '900/2', 'copy_place' => 'اصفهان', 'commissioned_by' => 'میرزا حسن', 'script_names' => 'نسخ و نستعلیق', 'script_style' => 'خوش',
            'lines' => 15, 'dimensions' => '11×13/5سم', 'paper' => 'دولت‌آبادی', 'binding' => 'تیماج مشکی',
            'flags' => ['is_illuminated' => 1, 'has_catchwords' => 1, 'is_autograph' => 0, 'no_such_flag' => 1],
            'notes' => [['type' => 'seal', 'text' => 'مهر بیضی «عبده محمد»'], ['type' => 'description', 'text' => ''], ['type' => 'defect', 'text' => 'افتادگی از آغاز']],
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');

        $copy = $this->created();
        $this->assertSame(['اصفهان', 'میرزا حسن', 'خوش', 15, '11×13/5سم', 'دولت‌آبادی', 'تیماج مشکی'],
            [$copy->copy_place, $copy->commissioned_by, $copy->script_style, $copy->lines, $copy->dimensions, $copy->paper, $copy->binding]);
        $this->assertSame([true, true, false, false], [$copy->is_illuminated, $copy->has_catchwords, $copy->is_autograph, $copy->is_corrected]);
        $this->assertNull($copy->lines_raw);
        // the scripts it names are linked, the first as its main hand, and counted
        $scripts = $copy->scripts()->orderByDesc('manuscript_script.is_primary')->get();
        // in the order ScriptNameCleaner gives them, as fahares:rebuild-scripts links the catalogues' copies
        $this->assertSame(ScriptNameCleaner::clean('نسخ و نستعلیق')['scripts'], $scripts->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['نسخ', 'نستعلیق'], $scripts->pluck('name')->all());
        $this->assertSame([1, 0], $scripts->map(fn ($s) => (int) $s->pivot->is_primary)->all());
        $this->assertSame([1, 1], $scripts->pluck('manuscripts_count')->all());
        $this->assertStringContainsString('نسخ', $copy->script_names);
        // the notes with a text, typed by an editor, in the order given
        $this->assertSame([[0, 'seal', 'مهر بیضی «عبده محمد»', 'editor'], [1, 'defect', 'افتادگی از آغاز', 'editor']],
            $copy->notes->map(fn ($n) => [$n->position, $n->type, $n->text, $n->typed_by])->all());
        $this->get(route('manuscripts.show', $copy))->assertOk()->assertSee('مهر بیضی «عبده محمد»')->assertSee('افتادگی از آغاز')->assertSee('دولت‌آبادی');

        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $copy->id)->where('action', 'create')->firstOrFail();
        $this->assertTrue($log->new_values['is_illuminated']);
        $this->assertArrayNotHasKey('is_autograph', $log->new_values);

        // a fill of the line layouts from the catalogues' text leaves its count alone
        $this->artisan('fahares:refresh-derived', ['--apply' => true, '--show' => 500])->assertSuccessful();
        $this->assertSame(15, $copy->fresh()->lines);

        $this->actingAs($this->admin)->post(route('admin.audit-logs.rollback', $log))->assertSessionHas('success');
        $this->assertSame(0, DB::table('manuscript_notes')->where('manuscript_id', $copy->id)->count());
        $this->assertSame(0, DB::table('manuscript_script')->where('manuscript_id', $copy->id)->count());
        $this->assertSame([0, 0], \App\Models\Script::whereIn('id', $scripts->pluck('id'))->pluck('manuscripts_count')->all());

        // a note without a type is refused
        $this->store(['shelfmark' => '901', 'notes' => [['type' => 'nonsense', 'text' => 'متن']]])->assertSessionHasErrors('notes.0.type');
    }
}
