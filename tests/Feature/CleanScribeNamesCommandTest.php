<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * fahares:clean-scribe-names: scribes whose name holds a remark, a hedge, a label or a date, or is no name (step 14.356)
 */
class CleanScribeNamesCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];
    private array $people = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'scribe-names') . '.json';
    }

    protected function tearDown(): void
    {
        DB::table('manuscript_people')->whereIn('manuscript_id', $this->manuscripts)->delete();
        DB::table('audit_logs')->where('auditable_type', \App\Models\Manuscript::class)->whereIn('auditable_id', $this->manuscripts)->delete();
        DB::table('audit_logs')->where('auditable_type', \App\Models\Person::class)->whereIn('auditable_id', $this->people)->delete();
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        DB::table('people')->whereIn('id', $this->people)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    /** @return array{0: int, 1: int} the copy and its scribe's person */
    private function copy(string $scribe, ?int $year = null, ?int $person = null): array
    {
        $this->works[] = $this->works[0] ?? DB::table('works')->insertGetId(['primary_title' => 'اثر آزمایشی نام کاتب', 'clean_title' => 'اثر آزمایشی نام کاتب',
            'slug' => 'test-scribe-names-' . uniqid(), 'volume_number' => 99, 'page_start' => 1]);
        $person ??= $this->people[] = DB::table('people')->insertGetId(['name' => $scribe, 'normalized_name' => \App\Helpers\TextNormalizer::normalize($scribe), 'slug' => 'test-scribe-name-' . uniqid()]);
        $id = $this->manuscripts[] = DB::table('manuscripts')->insertGetId(['work_id' => $this->works[0], 'volume_number' => 99, 'page_start' => 1,
            'shelfmark' => 'N-' . count($this->manuscripts), 'scribe_name' => $scribe, 'scribe_id' => $person, 'copy_date_from' => $year, 'copy_date_to' => $year]);
        DB::table('manuscript_people')->insert(['manuscript_id' => $id, 'person_id' => $person, 'role' => 'scribe', 'position' => 0]);

        return [$id, $person];
    }

    private function run_(array $items, bool $apply = true): void
    {
        file_put_contents($this->file, json_encode(['items' => $items], JSON_UNESCAPED_UNICODE));
        $this->artisan('fahares:clean-scribe-names', ['--file' => $this->file] + ($apply ? ['--apply' => true] : []))->assertExitCode(0);
    }

    public function test_a_remark_goes_to_the_note_a_text_keeps_no_person_and_the_authors_hand_is_marked(): void
    {
        [$remark, $pRemark] = $this->copy('مرتضی غروی آزمایشی (فرزند شیخ شعبان)');
        [$text, $pText] = $this->copy('به خطهای مختلف آزمایشی');
        [$auto, $pAuto] = $this->copy('به خط مؤلف آزمایشی');
        $items = [
            ['name' => 'مرتضی غروی آزمایشی (فرزند شیخ شعبان)', 'action' => 'rename', 'new_name' => 'مرتضی غروی آزمایشی', 'note' => 'فرزند شیخ شعبان'],
            ['name' => 'به خطهای مختلف آزمایشی', 'action' => 'text'],
            ['name' => 'به خط مؤلف آزمایشی', 'action' => 'autograph'],
        ];

        $this->run_($items, false);
        $this->assertSame('مرتضی غروی آزمایشی (فرزند شیخ شعبان)', DB::table('people')->where('id', $pRemark)->value('name'));

        $this->run_($items);
        $this->assertSame('مرتضی غروی آزمایشی', DB::table('people')->where('id', $pRemark)->value('name'));
        $this->assertSame('مرتضی غروی آزمایشی', DB::table('manuscripts')->where('id', $remark)->value('scribe_name'));
        $this->assertSame('فرزند شیخ شعبان', DB::table('manuscript_people')->where('manuscript_id', $remark)->value('note'));

        $row = DB::table('manuscript_people')->where('manuscript_id', $text)->first();
        $this->assertSame([null, 'به خطهای مختلف آزمایشی'], [$row->person_id, $row->name]);
        $this->assertNull(DB::table('people')->where('id', $pText)->first());
        $this->assertNull(DB::table('manuscripts')->where('id', $text)->value('scribe_id'));

        $copy = DB::table('manuscripts')->where('id', $auto)->first();
        $this->assertSame([1, null, null], [(int) $copy->is_autograph, $copy->scribe_name, $copy->scribe_id]);
        $this->assertSame(0, DB::table('manuscript_people')->where('manuscript_id', $auto)->count());
        $this->assertNull(DB::table('people')->where('id', $pAuto)->first());

        $this->run_($items);
        $this->assertSame('فرزند شیخ شعبان', DB::table('manuscript_people')->where('manuscript_id', $remark)->value('note'));
    }

    public function test_the_cleaned_name_joins_the_scribe_of_that_name_only_where_the_dates_agree(): void
    {
        [, $known] = $this->copy('ابراهیم بن محمد کرکی آزمایشی', 1070);
        [$near, $pNear] = $this->copy('ابراهیم بن محمد کرکی آزمایشی، تا 1071ق', 1071);
        [, $far] = $this->copy('حسین بن علی قمی آزمایشی', 900);
        [$late, $pLate] = $this->copy('حسین بن علی قمی آزمایشی (کاتب بخش نونویس)', 1300);

        $this->run_([
            ['name' => 'ابراهیم بن محمد کرکی آزمایشی، تا 1071ق', 'action' => 'rename', 'new_name' => 'ابراهیم بن محمد کرکی آزمایشی', 'note' => 'تا 1071ق'],
            ['name' => 'حسین بن علی قمی آزمایشی (کاتب بخش نونویس)', 'action' => 'rename', 'new_name' => 'حسین بن علی قمی آزمایشی', 'note' => 'کاتب بخش نونویس'],
        ]);

        $this->assertSame($known, (int) DB::table('manuscript_people')->where('manuscript_id', $near)->value('person_id'));
        $this->assertNull(DB::table('people')->where('id', $pNear)->first());
        // four centuries apart: two people of one name
        $this->assertSame($pLate, (int) DB::table('manuscript_people')->where('manuscript_id', $late)->value('person_id'));
        $this->assertSame('حسین بن علی قمی آزمایشی', DB::table('people')->where('id', $pLate)->value('name'));
        $this->assertNotNull(DB::table('people')->where('id', $far)->first());
        DB::table('audit_logs')->where('auditable_type', \App\Models\Person::class)->whereIn('auditable_id', [$pNear, $known])->delete();
    }
}
