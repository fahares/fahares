<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * fahares:import-copy-notes: a copy's typed notes in manuscript_notes (backlog item 87, step 14.254)
 */
class ImportCopyNotesCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'notes') . '.gz';
    }

    protected function tearDown(): void
    {
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function copy(string $shelfmark): int
    {
        $work = $this->works[] = \Tests\Support\InsertRecords::work([
            'primary_title' => 'اثر آزمایشی یادداشت', 'clean_title' => 'اثر آزمایشی یادداشت',
            'slug' => 'test-notes-' . uniqid(), 'volume_number' => 99, 'page_start' => 1,
        ]);

        return $this->manuscripts[] = \Tests\Support\InsertRecords::copy([
            'work_id' => $work, 'volume_number' => 99, 'page_start' => 1, 'shelfmark' => $shelfmark,
        ]);
    }

    private function file(array $records): void
    {
        file_put_contents($this->file, gzencode(implode("\n", array_map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $records)) . "\n"));
    }

    private function run_(bool $apply = true)
    {
        return $this->artisan('fahares:import-copy-notes', ['--file' => $this->file] + ($apply ? ['--apply' => true] : []));
    }

    public function test_the_notes_of_a_copy_are_written_in_the_file_s_order_and_a_second_run_writes_nothing()
    {
        $id = $this->copy('1234/5');
        $other = $this->copy('77');
        $this->file([
            ['id' => $id, 'shelfmark' => '1234/5', 'notes' => [
                ['type' => 'marginalia', 'text' => 'با حواشی مؤلف', 'typed_by' => 'sonnet', 'by_author' => true, 'by_author_hedge' => 'گویا'],
                ['type' => 'endowment', 'text' => 'نائینی', 'typed_by' => 'field'],
                ['type' => 'ownership', 'text' => 'تملک محمد با مهر مربع', 'typed_by' => 'opus', 'also' => ['seal'], 'hedge' => 'ظاهراً'],
            ]],
            // another shelfmark than the site's: the ids are another database's
            ['id' => $other, 'shelfmark' => '78', 'notes' => [['type' => 'seal', 'text' => 'ناصرالدین شاه', 'typed_by' => 'field']]],
            ['id' => 2000000000, 'shelfmark' => '1', 'notes' => [['type' => 'seal', 'text' => 'ناصرالدین شاه', 'typed_by' => 'field']]],
        ]);

        $this->run_(false)->expectsOutputToContain('Would write 3 notes of 1 copies.')->assertExitCode(0);
        $this->assertSame(0, DB::table('manuscript_notes')->whereIn('manuscript_id', $this->manuscripts)->count());

        $this->run_()->expectsOutputToContain('Wrote 3 notes of 1 copies.')->assertExitCode(0);
        $notes = Manuscript::find($id)->notes;
        $this->assertSame(['marginalia', 'endowment', 'ownership'], $notes->pluck('type')->all());
        $this->assertSame([1, 2, 3], $notes->pluck('position')->all());
        $this->assertTrue($notes[0]->by_author);
        $this->assertSame('گویا', $notes[0]->by_author_hedge);
        $this->assertSame(['seal'], $notes[2]->also);
        $this->assertSame('ظاهراً', $notes[2]->hedge);
        $this->assertSame(0, DB::table('manuscript_notes')->where('manuscript_id', $other)->count());

        $this->run_()->expectsOutputToContain('Wrote 0 notes of 0 copies.')->assertExitCode(0);
    }

    public function test_a_changed_copy_is_replaced_as_a_whole_and_a_copy_an_editor_typed_is_kept()
    {
        $id = $this->copy('10');
        $edited = $this->copy('11');
        $this->file([
            ['id' => $id, 'shelfmark' => '10', 'notes' => [['type' => 'other', 'text' => 'یک', 'typed_by' => 'rule'], ['type' => 'annex', 'text' => 'دو', 'typed_by' => 'sonnet']]],
            ['id' => $edited, 'shelfmark' => '11', 'notes' => [['type' => 'other', 'text' => 'سه', 'typed_by' => 'rule']]],
        ]);
        $this->run_()->assertExitCode(0);
        DB::table('manuscript_notes')->where('manuscript_id', $edited)->update(['type' => 'collation', 'typed_by' => 'editor']);

        $this->file([
            ['id' => $id, 'shelfmark' => '10', 'notes' => [['type' => 'contents', 'text' => 'یک', 'typed_by' => 'opus']]],
            ['id' => $edited, 'shelfmark' => '11', 'notes' => [['type' => 'contents', 'text' => 'سه', 'typed_by' => 'opus']]],
        ]);
        $this->run_()->expectsOutputToContain('Wrote 1 notes of 1 copies.')->assertExitCode(0);
        $this->assertSame(['contents'], DB::table('manuscript_notes')->where('manuscript_id', $id)->pluck('type')->all());
        $this->assertSame(['collation'], DB::table('manuscript_notes')->where('manuscript_id', $edited)->pluck('type')->all());
    }

    public function test_the_copy_page_shows_the_typed_notes_in_their_sections_in_place_of_the_old_lists()
    {
        $id = $this->copy('30');
        $plain = $this->copy('31');
        $old = json_encode(['editorial_notes' => ['یادداشت کهنۀ فهرست‌نگار'], 'ownership_and_seals' => ['مهر: فهرست کهنه'], 'residual_notes' => ['فوائد کهنه']], JSON_UNESCAPED_UNICODE);
        DB::table('manuscripts')->whereIn('id', [$id, $plain])->update(['metadata' => $old]);
        $this->file([['id' => $id, 'shelfmark' => '30', 'notes' => [
            ['type' => 'scope', 'text' => 'از آغاز تا باب دوم', 'typed_by' => 'field'],
            ['type' => 'marginalia', 'text' => 'حواشی با نشان منه', 'typed_by' => 'sonnet', 'by_author' => true],
            ['type' => 'seal', 'text' => 'ناصرالدین شاه', 'typed_by' => 'field'],
        ]]]);
        $this->run_()->assertExitCode(0);

        $page = $this->get(route('manuscripts.show', ['id' => Manuscript::find($id)->getRouteKey()]))->assertOk();
        $page->assertSeeInOrder(['محتوای نسخه', 'بخش موجود', 'از آغاز تا باب دوم', 'مهرها، تملک‌ها و وقف‌نامه‌ها', 'ناصرالدین شاه', 'نشان‌های متنی', 'حواشی با نشان منه', 'از مؤلف']);
        $page->assertDontSee('یادداشت کهنۀ فهرست‌نگار')->assertDontSee('فهرست کهنه')->assertDontSee('فوائد کهنه');

        // the list of results counts the typed notes of provenance, not the old list
        $this->assertSame(1, Manuscript::find($id)->provenanceCount());
        $this->assertSame(1, Manuscript::find($plain)->provenanceCount());

        // a copy without typed notes keeps the three lists
        $this->get(route('manuscripts.show', ['id' => Manuscript::find($plain)->getRouteKey()]))->assertOk()
            ->assertSee('یادداشت کهنۀ فهرست‌نگار')->assertSee('مهر: فهرست کهنه')->assertSee('فوائد کهنه');
    }

    public function test_each_note_is_a_field_of_its_own_and_a_note_of_provenance_can_be_added_or_removed()
    {
        $id = $this->copy('40');
        $other = $this->copy('41');
        $records = [
            ['id' => $id, 'shelfmark' => '40', 'notes' => [['type' => 'seal', 'text' => 'مهر نخست', 'typed_by' => 'field'], ['type' => 'ownership', 'text' => 'محمد', 'typed_by' => 'field']]],
            ['id' => $other, 'shelfmark' => '41', 'notes' => [['type' => 'contents', 'text' => 'فهرست اشعار', 'typed_by' => 'sonnet']]],
        ];
        $this->file($records);
        $this->run_()->assertExitCode(0);
        $copy = Manuscript::find($id);
        [$seal, $owner] = $copy->notes->all();

        $fields = \App\Suggestions\SuggestionRegistry::recordFields($copy);
        $this->assertArrayHasKey('note_' . $seal->id, $fields);
        $this->assertArrayHasKey('note_new_seal', $fields);
        $this->assertArrayNotHasKey('ownership_and_seals', $fields);
        $this->assertSame('مهر نخست', \App\Suggestions\SuggestionRegistry::currentValue($copy, ['name' => 'note_' . $seal->id] + $fields['note_' . $seal->id]));
        // another copy's note is no field of this one
        $foreign = Manuscript::find($other)->notes->first();
        $this->assertNull(\App\Suggestions\SuggestionRegistry::fieldOf($copy, 'note_' . $foreign->id));
        $this->assertNotNull(\App\Suggestions\SuggestionRegistry::fieldOf($copy, 'note_' . $owner->id));

        $applier = app(\App\Suggestions\SuggestionApplier::class);
        $apply = fn (string $name, ?string $value) => $applier->apply($copy, \App\Suggestions\SuggestionRegistry::fieldOf($copy->fresh(), $name), ['applied_value' => $value]);
        $changed = $apply('note_' . $seal->id, 'مهر «عبده محمد» (بیضی)');
        $this->assertSame('مهر نخست', $changed['old']['notes']['text']);
        $apply('note_' . $owner->id, null);
        $added = $apply('note_new_endowment', 'وقف مدرسۀ سپهسالار، ۱۲۹۷');

        $notes = $copy->fresh()->notes;
        $this->assertSame([['seal', 'مهر «عبده محمد» (بیضی)', 'editor'], ['endowment', 'وقف مدرسۀ سپهسالار، ۱۲۹۷', 'editor']],
            $notes->map(fn ($n) => [$n->type, $n->text, $n->typed_by])->all());
        $this->assertSame($notes[1]->id, $added['new']['notes']['id']);

        // a later import leaves the copy alone: an editor's note, and (for the other copy) a note a reviewer removed
        $foreignRemoved = $applier->apply(Manuscript::find($other), \App\Suggestions\SuggestionRegistry::fieldOf(Manuscript::find($other), 'note_' . $foreign->id), ['applied_value' => null]);
        \App\Models\AuditLog::create(['auditable_type' => Manuscript::class, 'auditable_id' => $other, 'action' => 'verify', 'old_values' => $foreignRemoved['old'], 'new_values' => $foreignRemoved['new']]);
        $this->file($records);
        $this->run_()->expectsOutputToContain('Wrote 0 notes of 0 copies.')->assertExitCode(0);
        $this->assertSame(0, DB::table('manuscript_notes')->where('manuscript_id', $other)->count());
        \App\Models\AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $other)->delete();
    }

    public function test_a_note_s_type_can_be_changed_and_a_note_of_any_type_added()
    {
        $id = $this->copy('50');
        $this->file([['id' => $id, 'shelfmark' => '50', 'notes' => [
            ['type' => 'contents', 'text' => 'مهر بیضی «عبده علی»', 'typed_by' => 'sonnet', 'also' => ['seal']],
            ['type' => 'other', 'text' => 'ناخوانا', 'typed_by' => 'site'],
        ]]]);
        $this->run_()->assertExitCode(0);
        $copy = Manuscript::find($id);
        [$note, $loose] = $copy->notes->all();
        $registry = \App\Suggestions\SuggestionRegistry::class;

        $fields = $registry::recordFields($copy);
        $type = $fields['note_type_' . $note->id];
        $this->assertSame('choice', $type['input']);
        $this->assertContains('مهر', $type['options']);
        $this->assertNotContains('دیگر', $type['options'], 'no note is made «other»');
        $this->assertContains('دیگر', $fields['note_type_' . $loose->id]['options'], '...but one that is may stay');
        $this->assertSame('محتوا', $registry::currentValue($copy, ['name' => 'note_type_' . $note->id] + $type));
        // a note of every type but «other» can be added, in every section
        $this->assertSame('افزودن مقابله', $fields['note_new_collation']['label']);
        $this->assertSame('افزودن یادداشت نسخه‌شناسی', $fields['note_new_codicology']['label']);
        $this->assertArrayNotHasKey('note_new_other', $fields);
        $this->assertNull($registry::fieldOf($copy, 'note_type_999999'));

        $applier = app(\App\Suggestions\SuggestionApplier::class);
        $apply = fn (string $name, ?string $value) => $applier->apply($copy, $registry::fieldOf($copy->fresh(), $name), ['applied_value' => $value]);
        $changed = $apply('note_type_' . $note->id, 'مهر');
        $this->assertSame('contents', $changed['old']['notes']['type']);
        $this->assertSame('seal', $changed['new']['notes']['type']);
        $apply('note_new_collation', 'مقابله با نسخۀ اصل، ۱۰۸۵');
        $this->assertSame([['seal', null, 'editor'], ['other', null, 'site'], ['collation', null, 'editor']],
            $copy->fresh()->notes->map(fn ($n) => [$n->type, $n->also, $n->typed_by])->all());
        try {
            $apply('note_type_' . $note->id, 'نوعی که نیست');
            $this->fail('an unknown type was taken');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('نوع یادداشت', $e->getMessage());
        }

        // the page: the changed note under provenance, the add buttons of each section it has, the type's own button;
        // a note of a section it lacks is added from the one button whose form asks the type (step 14.318)
        $page = $this->get(route('manuscripts.show', ['id' => $copy->getRouteKey()]))->assertOk();
        $page->assertSee('data-suggest-field="note_type_' . $note->id . '"', false)->assertSee('data-suggest-field="note_new_collation"', false)
            ->assertDontSee('data-suggest-field="note_new_decoration"', false)->assertSee('data-suggest-new-note', false)
            ->assertSee('<option value="note_new_decoration">آرایه</option>', false);
    }

    public function test_the_types_of_a_copy_s_notes_are_a_filter_of_the_search()
    {
        $sealed = $this->copy('60');
        $both = $this->copy('61');
        $bare = $this->copy('62');
        $this->file([
            ['id' => $sealed, 'shelfmark' => '60', 'notes' => [['type' => 'seal', 'text' => 'مهر', 'typed_by' => 'field']]],
            ['id' => $both, 'shelfmark' => '61', 'notes' => [['type' => 'ownership', 'text' => 'تملک و مهر', 'typed_by' => 'opus', 'also' => ['seal']], ['type' => 'collation', 'text' => 'مقابله', 'typed_by' => 'rule']]],
        ]);
        $this->run_()->assertExitCode(0);

        $this->assertSame(['ownership', 'seal', 'collation'], Manuscript::find($both)->toSearchableArray()['note_types'], 'a note\'s second type too, in the order of the types');
        $this->assertSame([], Manuscript::find($bare)->toSearchableArray()['note_types']);

        $filters = \App\Support\SearchFilters::fromRequest(\Illuminate\Http\Request::create('/search', 'GET', ['note_types' => ['seal', 'bogus', 'collation']]), 'manuscripts');
        $this->assertSame(['collation', 'seal'], collect($filters->get('note_types'))->sort()->values()->all());
        $this->assertStringContainsString('note_types = "seal"', $filters->engine());
        $this->assertContains('یادداشت: مهر', array_column($filters->active(), 0));
        $found = fn (array $types) => \App\Support\SearchFilters::fromRequest(\Illuminate\Http\Request::create('/search', 'GET', ['note_types' => $types]), 'manuscripts')
            ->apply(Manuscript::query())->whereIn('manuscripts.id', [$sealed, $both, $bare])->orderBy('manuscripts.id')->pluck('manuscripts.id')->all();
        $this->assertSame([$sealed, $both], $found(['seal']));
        $this->assertSame([$both], $found(['seal', 'collation']));

        // the search page offers the types and names the chosen one
        $this->get(route('search', ['type' => 'manuscripts', 'note_types' => ['seal']]))->assertOk()
            ->assertSee('name="note_types[]"', false)->assertSee('یادداشت: مهر');

        // the index command counts every copy, with or without notes
        $this->artisan('fahares:index-note-types', ['--from' => $sealed])->expectsOutputToContain('2 of them with notes')->assertExitCode(0);
    }

    public function test_a_note_of_an_unknown_type_stops_the_run()
    {
        $id = $this->copy('20');
        $this->file([['id' => $id, 'shelfmark' => '20', 'notes' => [['type' => 'binding', 'text' => 'جلد', 'typed_by' => 'sonnet']]]]);
        $this->run_()->assertExitCode(1);
        $this->assertSame(0, DB::table('manuscript_notes')->where('manuscript_id', $id)->count());
    }

    public function test_an_empty_list_removes_the_copys_notes_unless_an_editor_typed_one(): void
    {
        $gone = $this->copy('E-1');
        $kept = $this->copy('E-2');
        DB::table('manuscript_notes')->insert([
            ['manuscript_id' => $gone, 'position' => 1, 'type' => 'codicology', 'text' => 'فرنگی', 'typed_by' => 'sonnet'],
            ['manuscript_id' => $kept, 'position' => 1, 'type' => 'codicology', 'text' => 'فرنگی', 'typed_by' => 'editor'],
        ]);
        $this->file([['id' => $gone, 'shelfmark' => 'E-1', 'notes' => []], ['id' => $kept, 'shelfmark' => 'E-2', 'notes' => []]]);

        $this->artisan('fahares:import-copy-notes', ['--file' => $this->file, '--apply' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('manuscript_notes')->where('manuscript_id', $gone)->count());
        $this->assertSame(1, DB::table('manuscript_notes')->where('manuscript_id', $kept)->count());
        DB::table('manuscript_notes')->where('manuscript_id', $kept)->delete();
    }
}
