<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * fahares:add-copy-scribes: the scribes after the first, from parser v2 (backlog item 87, step 14.294)
 */
class AddCopyScribesCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];
    private array $people = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'scribes') . '.json';
    }

    protected function tearDown(): void
    {
        $made = DB::table('manuscript_people')->whereIn('manuscript_id', $this->manuscripts)->whereNotNull('person_id')->pluck('person_id')->all();
        DB::table('manuscript_people')->whereIn('manuscript_id', $this->manuscripts)->delete();
        DB::table('audit_logs')->where('auditable_type', \App\Models\Manuscript::class)->whereIn('auditable_id', $this->manuscripts)->delete();
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        DB::table('people')->whereIn('id', array_merge($this->people, $made))->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function person(string $name): int
    {
        return $this->people[] = DB::table('people')->insertGetId([
            'name' => $name, 'normalized_name' => \App\Helpers\TextNormalizer::normalize($name), 'slug' => 'test-scribe-' . uniqid(),
        ]);
    }

    private function copy(string $shelfmark, string $scribe): array
    {
        $work = $this->works[] = DB::table('works')->insertGetId([
            'primary_title' => 'اثر آزمایشی کاتبان', 'clean_title' => 'اثر آزمایشی کاتبان',
            'slug' => 'test-scribes-' . uniqid(), 'volume_number' => 99, 'page_start' => 1,
        ]);
        $person = $this->person($scribe);
        $id = $this->manuscripts[] = DB::table('manuscripts')->insertGetId([
            'work_id' => $work, 'volume_number' => 99, 'page_start' => 1, 'shelfmark' => $shelfmark, 'scribe_name' => $scribe, 'scribe_id' => $person,
        ]);
        DB::table('manuscript_people')->insert(['manuscript_id' => $id, 'person_id' => $person, 'role' => 'scribe', 'position' => 0]);

        return [$id, $person];
    }

    private function run_(array $records, bool $apply = true): void
    {
        file_put_contents($this->file, json_encode(['records' => $records], JSON_UNESCAPED_UNICODE));
        $this->artisan('fahares:add-copy-scribes', ['--file' => $this->file] + ($apply ? ['--apply' => true] : []))->assertExitCode(0);
    }

    private function rows(int $id): array
    {
        return DB::table('manuscript_people as mp')->leftJoin('people', 'people.id', '=', 'mp.person_id')->where('mp.manuscript_id', $id)
            ->orderBy('mp.position')->get(['people.name as person', 'mp.name', 'mp.note'])->map(fn ($r) => [$r->person ?? $r->name, $r->note])->all();
    }

    public function test_a_list_held_as_one_name_becomes_one_row_a_scribe(): void
    {
        [$id, $whole] = $this->copy('T-1', 'درویش عبدالمجید طالقانی در 1182ق، محمدهاشم بن محمد صالح لؤلؤی اصفهانی در 1187ق، محمد شفیع');
        $known = $this->person('محمدهاشم بن محمد صالح لؤلؤی اصفهانی');
        $record = ['id' => $id, 'shelfmark' => 'T-1', 'first' => ['from' => 'درویش عبدالمجید طالقانی در 1182ق', 'to' => 'درویش عبدالمجید طالقانی', 'note' => 'در 1182ق'],
            'scribes' => [['name' => 'محمدهاشم بن محمد صالح لؤلؤی اصفهانی', 'note' => 'در 1187ق'], ['name' => 'محمد شفیع']]];

        $this->run_([$record], false);
        $this->assertCount(1, $this->rows($id));

        $this->run_([$record]);
        $this->assertSame([['درویش عبدالمجید طالقانی', 'در 1182ق'], ['محمدهاشم بن محمد صالح لؤلؤی اصفهانی', 'در 1187ق'], ['محمد شفیع', null]], $this->rows($id));
        $copy = DB::table('manuscripts')->where('id', $id)->first();
        $this->assertSame('درویش عبدالمجید طالقانی', $copy->scribe_name);
        $this->assertNotSame($whole, (int) $copy->scribe_id);
        // the known person is linked, the short name stays a name, and the list's own person loses the copy
        $this->assertSame($known, (int) DB::table('manuscript_people')->where('manuscript_id', $id)->where('position', 1)->value('person_id'));
        $this->assertNull(DB::table('manuscript_people')->where('manuscript_id', $id)->where('position', 2)->value('person_id'));
        $this->assertSame(0, DB::table('manuscript_people')->where('person_id', $whole)->count());

        $this->run_([$record]);
        $this->assertCount(3, $this->rows($id));
    }

    public function test_a_copy_with_another_scribe_or_several_is_left(): void
    {
        [$other] = $this->copy('T-2', 'کاتبی دیگر از جای دیگر');
        [$several] = $this->copy('T-3', 'حسن بن علی کاشانی');
        DB::table('manuscript_people')->insert(['manuscript_id' => $several, 'name' => 'نفر دوم', 'role' => 'scribe', 'position' => 1]);
        [$wrong] = $this->copy('T-4', 'حسن بن علی کاشانی');

        $this->run_([
            ['id' => $other, 'shelfmark' => 'T-2', 'first' => ['from' => 'حسن بن علی کاشانی', 'to' => 'حسن بن علی کاشانی'], 'scribes' => [['name' => 'محمد بن حسن قمی']]],
            ['id' => $several, 'shelfmark' => 'T-3', 'first' => ['from' => 'حسن بن علی کاشانی', 'to' => 'حسن بن علی کاشانی'], 'scribes' => [['name' => 'محمد بن حسن قمی']]],
            ['id' => $wrong, 'shelfmark' => 'T-9', 'first' => ['from' => 'حسن بن علی کاشانی', 'to' => 'حسن بن علی کاشانی'], 'scribes' => [['name' => 'محمد بن حسن قمی']]],
        ]);

        $this->assertCount(1, $this->rows($other));
        $this->assertCount(2, $this->rows($several));
        $this->assertCount(1, $this->rows($wrong));
    }
}
