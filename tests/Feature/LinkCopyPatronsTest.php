<?php

namespace Tests\Feature;

use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use App\Support\KnownPatrons;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The patrons of copies link to their person records when the copy's date says who they are (step 14.120). */
class LinkCopyPatronsTest extends TestCase
{
    use DatabaseTransactions;

    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $title = 'اثر آزمایشی سفارش ' . uniqid();
        $this->work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'patron-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function person(string $name, ?int $death = null): Person
    {
        DB::table('people')->where('name', $name)->orWhere('normalized_name', \App\Helpers\TextNormalizer::normalize($name))->delete();

        return Person::create(['name' => $name, 'normalized_name' => \App\Helpers\TextNormalizer::normalize($name), 'slug' => 'patron-' . uniqid(), 'death_year_hijri' => $death]);
    }

    /** A copy with a patron row, as the catalogue writes the name; its id */
    private function patron(string $name, ?string $date = null, bool $reviewer = false): int
    {
        $copy = Manuscript::create(['work_id' => $this->work->id, 'commissioned_by' => $name, 'copy_date_raw' => $date, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        $suggestion = $reviewer ? FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $copy->id, 'kind' => 'field', 'field_name' => 'commissioned_by',
            'suggested_value' => $name, 'rationale_citation' => 'فهرست', 'status' => 'approved', 'user_id' => User::factory()->create()->id])->id : null;

        return DB::table('manuscript_people')->insertGetId(['manuscript_id' => $copy->id, 'name' => $name, 'role' => 'patron', 'position' => 0, 'suggestion_id' => $suggestion]);
    }

    private function linked(int $row): ?int
    {
        return DB::table('manuscript_people')->where('id', $row)->value('person_id');
    }

    public function test_rulers_titles_and_full_names_link_by_the_year_of_the_copy()
    {
        DB::table('manuscript_people')->where('role', 'patron')->delete();
        $king = $this->person('ناصرالدین قاجار، شاه ایران', 1313);
        $masud = $this->person('ظل السلطان، مسعود میرزا بن ناصر', 1336);
        $farhad = $this->person('فرهاد میرزا قاجار', 1305);
        $scholar = $this->person('حکمت آزمایشی، علی اصغر بن محمد', 1359);
        $common = $this->person('میرزا محمد علی');

        $rows = [
            'king' => $this->patron('ناصرالدین شاه', '1290ق'),
            'king_no_year' => $this->patron('ناصر الدین شاه قاجار'),
            'king_dead' => $this->patron('ناصرالدین شاه', '1320ق'),
            'library' => $this->patron('کتابخانه سرکار ظل السلطان', '1310ق'),
            'other_holder' => $this->patron('ظل السلطان', '1254ق'),
            'title_no_year' => $this->patron('ظل السلطان'),
            'one_holder_no_year' => $this->patron('معتمد الدوله فرهاد میرزا'),
            'full_name' => $this->patron('حکمت آزمایشی، علی اصغر بن محمد', '1330ق'),
            'full_name_dead' => $this->patron('حکمت آزمایشی، علی اصغر بن محمد', '1365ق'),
            'common' => $this->patron('میرزا محمد علی', '1250ق'),
            'kin' => $this->patron('پدرش ناصرالدین شاه', '1290ق'),
            'several' => $this->patron('بهمن میرزا برای محمد شاه قاجار', '1260ق'),
            'reviewer' => $this->patron('ناصرالدین شاه', '1290ق', reviewer: true),
        ];

        $this->artisan('fahares:link-copy-patrons', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertNull($this->linked($rows['king']), 'a dry run writes nothing');

        $this->artisan('fahares:link-copy-patrons')->expectsOutputToContain('Written')->assertSuccessful();
        $this->assertSame([$king->id, $king->id, null], [$this->linked($rows['king']), $this->linked($rows['king_no_year']), $this->linked($rows['king_dead'])]);
        $this->assertSame([$masud->id, null, null], [$this->linked($rows['library']), $this->linked($rows['other_holder']), $this->linked($rows['title_no_year'])]);
        $this->assertSame($farhad->id, $this->linked($rows['one_holder_no_year']));
        $this->assertSame([$scholar->id, null], [$this->linked($rows['full_name']), $this->linked($rows['full_name_dead'])]);
        $this->assertSame([null, null, null, null], [$this->linked($rows['common']), $this->linked($rows['kin']), $this->linked($rows['several']), $this->linked($rows['reviewer'])]);
        $this->assertNotSame($common->id, $this->linked($rows['common']));
        $this->assertSame('ناصرالدین شاه', DB::table('manuscript_people')->where('id', $rows['king'])->value('name'), 'the name as written stays');
    }

    public function test_a_title_holder_with_a_death_year_gets_a_record_when_there_is_none()
    {
        DB::table('manuscript_people')->where('role', 'patron')->delete();
        DB::table('people')->where('name', 'طباطبائی بروجردی، حسین بن علی')->delete();
        $row = $this->patron('حضرت آیة الله العظمی بروجردی', '1365ق');
        $this->patron('آیت الله بروجردی', '1370ق');

        $this->artisan('fahares:link-copy-patrons')->assertSuccessful();

        $person = Person::find($this->linked($row));
        $this->assertSame(['طباطبائی بروجردی، حسین بن علی', 1380], [$person->name, $person->death_year_hijri]);
        $this->assertSame(1, Person::where('name', 'طباطبائی بروجردی، حسین بن علی')->count(), 'made once');
        $this->assertSame('ظل السلطان', KnownPatrons::core('تقدیم به کتابخانه سرکار ظل السلطان'));
    }
}
