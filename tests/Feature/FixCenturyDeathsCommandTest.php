<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A lone number of 15 or less in a person's life dates is the century, or the year for the people known to have died
 * then (step 14.173)
 */
class FixCenturyDeathsCommandTest extends TestCase
{
    private function person(string $name, ?string $raw, array $cols = []): int
    {
        return DB::table('people')->insertGetId(['name' => $name, 'normalized_name' => $name, 'slug' => 'century-' . md5($name), 'life_dates_raw' => $raw] + $cols);
    }

    private function cols(int $id): array
    {
        return array_values((array) DB::table('people')->where('id', $id)->first(['death_year_hijri', 'century_hijri', 'death_from', 'death_to', 'death_hedge_raw']));
    }

    public function test_the_century_or_the_known_year_is_written_only_with_apply_and_a_run_can_be_repeated()
    {
        $asYear = ['death_from' => 13, 'death_to' => 13];
        $bare = $this->person('معلم آزمایشی، عبدالرضا', '- 13', $asYear);
        $hedged = $this->person('فراهانی آزمایشی، علی', '- 13 ؟ قمری', $asYear + ['death_hedge_raw' => '؟', 'death_certainty' => 'doubtful']);
        $both = $this->person('فیضی آزمایشی، محمد حسین', '13ق - 13 قمری', $asYear);
        $prophet = $this->person('محمد (ص)، پیامبر اسلام', '- 11 قمری', ['death_from' => 11, 'death_to' => 11]);
        $firstCentury = $this->person('علی آزمایشی', '- 40 قمری', ['death_from' => 40, 'death_to' => 40]);
        $afterBirth = $this->person('کازرونی آزمایشی', '1305ق-14 قمری', ['death_year_hijri' => 1305, 'century_hijri' => 14, 'death_from' => 1301, 'death_to' => 1400]);

        $this->artisan('fahares:fix-century-deaths')->assertSuccessful();
        $this->assertSame([null, null, 13, 13, null], $this->cols($bare));

        $this->artisan('fahares:fix-century-deaths', ['--apply' => true])->assertSuccessful();
        $this->assertSame([null, 13, 1201, 1300, null], $this->cols($bare));
        $this->assertSame([null, 13, 1201, 1300, '؟'], $this->cols($hedged));
        $this->assertSame([null, 13, 1201, 1300, null], $this->cols($both));
        $this->assertSame([11, 1, 11, 11, null], $this->cols($prophet));
        $this->assertSame([null, null, 40, 40, null], $this->cols($firstCentury));
        $this->assertSame([1305, 14, 1301, 1400, null], $this->cols($afterBirth));

        $this->artisan('fahares:fix-century-deaths', ['--apply' => true])->expectsOutputToContain('0 as a century, 0 as a year, 4 already right')->assertSuccessful();
    }
}
