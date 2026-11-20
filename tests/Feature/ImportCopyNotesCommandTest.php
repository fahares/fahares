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
        $work = $this->works[] = DB::table('works')->insertGetId([
            'primary_title' => 'اثر آزمایشی یادداشت', 'clean_title' => 'اثر آزمایشی یادداشت',
            'slug' => 'test-notes-' . uniqid(), 'volume_number' => 99, 'page_start' => 1,
        ]);

        return $this->manuscripts[] = DB::table('manuscripts')->insertGetId([
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

        // a copy without typed notes keeps the three lists
        $this->get(route('manuscripts.show', ['id' => Manuscript::find($plain)->getRouteKey()]))->assertOk()
            ->assertSee('یادداشت کهنۀ فهرست‌نگار')->assertSee('مهر: فهرست کهنه')->assertSee('فوائد کهنه');
    }

    public function test_a_note_of_an_unknown_type_stops_the_run()
    {
        $id = $this->copy('20');
        $this->file([['id' => $id, 'shelfmark' => '20', 'notes' => [['type' => 'binding', 'text' => 'جلد', 'typed_by' => 'sonnet']]]]);
        $this->run_()->assertExitCode(1);
        $this->assertSame(0, DB::table('manuscript_notes')->where('manuscript_id', $id)->count());
    }
}
