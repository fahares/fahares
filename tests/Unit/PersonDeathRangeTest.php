<?php

namespace Tests\Unit;

use App\Models\Person;
use PHPUnit\Framework\TestCase;

/**
 * A person's death as a range of Hijri years with the catalogue's hedge, read from their life dates (backlog item 96)
 */
class PersonDeathRangeTest extends TestCase
{
    private function range(?string $raw, ?int $year = null): array
    {
        $c = Person::deathColumns($raw, $year);

        return [$c['death_from'], $c['death_to'], $c['death_hedge_raw']];
    }

    public function test_the_death_is_what_follows_the_dash_with_its_own_hedge()
    {
        $this->assertSame([505, 505, null], $this->range('450 - 505 قمری'));
        $this->assertSame([1124, 1124, '؟'], $this->range('1040 - 1124 ؟ قمری'));
        $this->assertSame([834, 834, null], $this->range('730؟ - 834 قمری'));
        $this->assertSame([449, 449, null], $this->range('- 449 قمری'));
        $this->assertSame([976, 976, '؟'], $this->range('- 976؟ قمری'));
        $this->assertSame([413, 413, null], $this->range('336 – 413 قمری'));
        $this->assertSame([1223, 1223, null], $this->range('1223ق 13 قمری'));
        $this->assertSame('doubtful', Person::deathColumns('-1336؟ قمری', null)['death_certainty']);
    }

    public function test_centuries_and_years_of_the_first_century()
    {
        $this->assertSame([1201, 1300, null], $this->range('ق13 قمری'));
        $this->assertSame([1101, 1200, null], $this->range('ق 12'));
        $this->assertSame([701, 800, null], $this->range('قرن 8 قمری'));
        $this->assertSame([1301, 1400, null], $this->range('1305-قرن 14 قمری'));
        // Two digits after a dash: a year, or the century when they are less than the birth year
        $this->assertSame([40, 40, null], $this->range('- 40 قمری'));
        $this->assertSame([1301, 1400, null], $this->range('1297-14 ق'));
    }

    public function test_a_lone_number_of_15_or_less_is_the_century_unless_the_death_year_says_it_is_the_year()
    {
        $this->assertSame([1201, 1300, null], $this->range('- 13'));
        $this->assertSame([1201, 1300, '؟'], $this->range('- 13 ؟ قمری'));
        $this->assertSame('doubtful', Person::deathColumns('- 13 ؟ قمری', null)['death_certainty']);
        $this->assertSame([1201, 1300, null], $this->range('13ق - 13 قمری'));
        $this->assertSame([701, 800, null], $this->range('- 8 قمری'));
        $this->assertSame([1401, 1500, null], $this->range('- ۱۵ ق'));
        // a death year elsewhere in that century does not make it a year
        $this->assertSame([1201, 1300, null], $this->range('- 13 قمری', 1250));
        // the year of someone who did die then: the death year column says so
        $this->assertSame([11, 11, null], $this->range('- 11 قمری', 11));
        $this->assertSame([7, 7, '؟'], $this->range('- 7 ؟ قمری', 7));
        // 16 and more stay years of the first century
        $this->assertSame([16, 16, null], $this->range('- 16 قمری'));
        $this->assertSame([50, 50, null], $this->range('3 - 50 قمری'));
    }

    public function test_other_calendars_give_no_range_and_a_death_year_stands_alone()
    {
        $this->assertSame([null, null, null], $this->range('1250 - 1312 شمسی'));
        $this->assertSame([null, null, null], $this->range('1884 - 1967 میلادی'));
        $this->assertSame([null, null, null], $this->range('ق 14 شمسی'));
        $this->assertSame([null, null, null], $this->range('427؟ - 347 ق.م'));
        $this->assertSame([null, null, null], $this->range('قمری'));
        $this->assertSame([1312, 1312, null], $this->range('1250 - 1312 شمسی', 1312));
        $this->assertSame([898, 898, null], $this->range(null, 898));
        $this->assertSame([null, null, null], $this->range(null));
    }
}
