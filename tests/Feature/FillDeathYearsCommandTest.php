<?php

namespace Tests\Feature;

use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A death year that is the year a person was alive in is emptied, and an empty one is filled from life dates that
 * give the year (step 14.179)
 */
class FillDeathYearsCommandTest extends TestCase
{
    private function person(string $name, ?string $raw, array $cols = []): int
    {
        return DB::table('people')->insertGetId(['name' => $name, 'normalized_name' => $name, 'slug' => 'death-year-' . md5($name), 'life_dates_raw' => $raw] + $cols);
    }

    private function cols(int $id): array
    {
        return array_values((array) DB::table('people')->where('id', $id)->first(['birth_year_hijri', 'death_year_hijri', 'century_hijri', 'death_from', 'death_to']));
    }

    public function test_the_three_kinds_are_written_only_with_apply_and_a_run_can_be_repeated()
    {
        $alive = $this->person('منزوی آزمایشی', '1107ق- 12 قمری', ['death_year_hijri' => 1107, 'century_hijri' => 12, 'death_from' => 1101, 'death_to' => 1200]);
        $midCentury = $this->person('نسفی آزمایشی', 'ق7 قمری', ['death_year_hijri' => 650, 'century_hijri' => 7, 'death_from' => 601, 'death_to' => 700]);
        $range = $this->person('کازرونی آزمایشی', '1305-قرن 14 قمری', ['century_hijri' => 14, 'death_from' => 1301, 'death_to' => 1400]);
        $first = $this->person('حسن آزمایشی', '3 - 50 قمری', ['death_from' => 50, 'death_to' => 50]);
        $wrongCentury = $this->person('طبری آزمایشی', '- 485 قمری', ['century_hijri' => 4, 'death_from' => 485, 'death_to' => 485]);
        $hedgedBirth = $this->person('خلال آزمایشی', '234؟ - 311 قمری', ['death_from' => 311, 'death_to' => 311]);
        $full = $this->person('غزالی آزمایشی', '450 - 505 قمری', ['birth_year_hijri' => 450, 'death_year_hijri' => 505, 'century_hijri' => 6, 'death_from' => 505, 'death_to' => 505]);

        $this->artisan('fahares:fill-death-years')->assertSuccessful();
        $this->assertSame([null, 1107, 12, 1101, 1200], $this->cols($alive));

        $this->artisan('fahares:fill-death-years', ['--apply' => true])->expectsOutputToContain('1 death years emptied, 1 ranges, 3 death years filled')->assertSuccessful();
        $this->assertSame([null, null, 12, 1107, 1200], $this->cols($alive));
        $this->assertSame([null, 650, 7, 601, 700], $this->cols($midCentury));
        $this->assertSame([null, null, 14, 1305, 1400], $this->cols($range));
        $this->assertSame([3, 50, 1, 50, 50], $this->cols($first));
        $this->assertSame([null, 485, 5, 485, 485], $this->cols($wrongCentury));
        $this->assertSame([null, 311, 4, 311, 311], $this->cols($hedgedBirth));
        $this->assertSame([450, 505, 6, 505, 505], $this->cols($full));

        $this->artisan('fahares:fill-death-years', ['--apply' => true])->expectsOutputToContain('0 death years emptied, 0 ranges, 0 death years filled')->assertSuccessful();
    }

    public function test_saving_life_dates_that_give_the_year_fills_an_empty_death_year()
    {
        $person = Person::create(['name' => 'علی آزمایشی', 'normalized_name' => 'علی آزمایشی', 'slug' => 'death-year-hook']);
        $person->update(['life_dates_raw' => '- 40 قمری']);
        $this->assertSame([40, 1, 40], [$person->death_year_hijri, $person->century_hijri, $person->death_from]);

        $century = Person::create(['name' => 'منزوی دوم آزمایشی', 'normalized_name' => 'منزوی دوم آزمایشی', 'slug' => 'death-year-hook-2', 'life_dates_raw' => '1107ق- 12 قمری']);
        $this->assertNull($century->death_year_hijri);
        $this->assertSame([1107, 1200], [$century->death_from, $century->death_to]);
    }
}
