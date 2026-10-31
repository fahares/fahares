<?php

namespace Tests\Feature;

use App\Console\Commands\FillDeathRangesCommand;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * People's life dates from the parser v2 decision file, and the death range made from them (backlog item 96)
 */
class FillDeathRangesCommandTest extends TestCase
{
    private function person(string $name, ?int $year): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'death-' . md5($name), 'death_year_hijri' => $year]);
    }

    private function cols(Person $p): array
    {
        return (array) DB::table('people')->where('id', $p->id)->first(['life_dates_raw', 'death_from', 'death_to', 'death_certainty', 'death_hedge_raw']);
    }

    public function test_names_compare_without_spaces_and_the_sites_longer_form()
    {
        $this->assertTrue(FillDeathRangesCommand::sameName('داعی دزفولی، عبدالله بن محمد باقر', 'داعی دزفولی، عبد الله بن محمد باقر'));
        $this->assertTrue(FillDeathRangesCommand::sameName('حسینی کرکی، حسین بن حیدر', 'حسینی کرکی ، حسین بن حیدر'));
        $this->assertTrue(FillDeathRangesCommand::sameName('علی بن ابی طالب (ع)، امام اول', 'علی بن ابی طالب (ع)'));
        $this->assertFalse(FillDeathRangesCommand::sameName('مجدالدین بغدادی، محمد', 'بغدادی، شرف بن مؤید'));
    }

    public function test_life_dates_are_chosen_by_most_works_holding_the_death_year_and_a_run_can_be_repeated()
    {
        $ahsai = $this->person('احسائی آزمایشی، احمد', 1241);
        $naini = $this->person('نائینی آزمایشی، محمد', 1250);
        $other = $this->person('دیگری آزمایشی', 900);
        $edited = $this->person('ویرایش‌شده آزمایشی', 1100);
        DB::table('people')->where('id', $edited->id)->update(['life_dates_raw' => '- 1100 قمری']);
        $plain = $this->person('تنها آزمایشی', 898);
        $work = fn (int $ordinal, string $title, Person $author) => Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => "test-death-v7-{$ordinal}",
            'volume_number' => 7, 'page_start' => $ordinal, 'author_id' => $author->id]);
        $works = [];
        $entry = function (int $ordinal, string $title, Person $author, string $name, string $raw) use ($work, &$works) {
            $work($ordinal, $title, $author);
            $works[] = ['key' => ['vol' => 7, 'ordinal' => $ordinal], 'title' => $title, 'provenance' => ['page_start' => $ordinal], 'author' => ['name' => $name, 'death_raw' => $raw]];
        };
        $entry(1, 'رساله یک', $ahsai, 'احسائی آزمایشی ، احمد', '1166 - 1241؟ ق');
        $entry(2, 'رساله دو', $ahsai, 'احسائی آزمایشی، احمد', '1166 - 1241؟ قمری');
        $entry(3, 'رساله سه', $ahsai, 'احسائی آزمایشی، احمد', '1166-1241 قمری');
        // A century that does not hold the death year is left out
        $entry(4, 'رساله چهار', $naini, 'نائینی آزمایشی، محمد', 'ق12 قمری');
        $entry(5, 'رساله پنج', $naini, 'نائینی آزمایشی، محمد', 'ق13 قمری');
        // Another name on the site's work: not this person's dates
        $entry(6, 'رساله شش', $other, 'کس دیگر', '- 950 قمری');
        $entry(7, 'رساله هفت', $edited, 'ویرایش‌شده آزمایشی', '- 1101 قمری');
        $file = tempnam(sys_get_temp_dir(), 'deaths');
        file_put_contents($file, json_encode(['works' => $works, 'peers' => []], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:fill-death-ranges', ['--file' => $file])->assertSuccessful();
        $this->assertNull($this->cols($ahsai)['life_dates_raw']);

        $this->artisan('fahares:fill-death-ranges', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $this->assertSame(['life_dates_raw' => '1166 - 1241؟ ق', 'death_from' => 1241, 'death_to' => 1241, 'death_certainty' => 'doubtful', 'death_hedge_raw' => '؟'], $this->cols($ahsai));
        $this->assertSame(['life_dates_raw' => 'ق13 قمری', 'death_from' => 1201, 'death_to' => 1300, 'death_certainty' => null, 'death_hedge_raw' => null], $this->cols($naini));
        $this->assertSame(['life_dates_raw' => null, 'death_from' => 900, 'death_to' => 900, 'death_certainty' => null, 'death_hedge_raw' => null], $this->cols($other));
        // Life dates already on the site are kept
        $this->assertSame('- 1100 قمری', $this->cols($edited)['life_dates_raw']);
        $this->assertSame([898, 898], array_values(array_slice($this->cols($plain), 1, 2)));

        $before = DB::table('people')->orderBy('id')->get(['id', 'updated_at', 'life_dates_raw', 'death_from', 'death_to', 'death_certainty', 'death_hedge_raw'])->toJson();
        $this->artisan('fahares:fill-death-ranges', ['--file' => $file, '--apply' => true])->assertSuccessful()->doesntExpectOutputToContain('people:changed');
        $this->assertSame($before, DB::table('people')->orderBy('id')->get(['id', 'updated_at', 'life_dates_raw', 'death_from', 'death_to', 'death_certainty', 'death_hedge_raw'])->toJson());
    }

    public function test_saving_a_person_keeps_the_range_in_step()
    {
        $person = $this->person('ذخیره آزمایشی', 1124);
        $this->assertSame(1124, $person->death_from);
        $person->update(['life_dates_raw' => '1040 - 1124 ؟ قمری']);
        $this->assertSame(['life_dates_raw' => '1040 - 1124 ؟ قمری', 'death_from' => 1124, 'death_to' => 1124, 'death_certainty' => 'doubtful', 'death_hedge_raw' => '؟'], $this->cols($person));
        $person->update(['life_dates_raw' => null, 'death_year_hijri' => 1130]);
        $this->assertSame([1130, 1130, null], [$person->fresh()->death_from, $person->fresh()->death_to, $person->fresh()->death_certainty]);
    }
}
