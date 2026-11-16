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
        $this->assertSame([1201, 1300, null], $this->range('- قرن 13 قمری'));
        $this->assertSame([1101, 1200, null], $this->range('-ق 12'));
        $this->assertSame([1305, 1400, null], $this->range('1305-قرن 14 قمری'));
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

    public function test_a_death_given_as_a_century_starts_at_the_year_before_the_dash_when_it_lies_in_that_century()
    {
        $this->assertSame([1107, 1200, null], $this->range('1107ق- 12 قمری'));
        $this->assertSame([903, 1000, null], $this->range('903؟ق- 10 قمری'));
        $this->assertSame([840, 900, null], $this->range('840ق - 9 قمری'));
        // the death year column does not change that
        $this->assertSame([1107, 1200, null], $this->range('1107ق- 12 قمری', 1107));
        // a year of the century before leaves the century whole, and a death year is not a span
        $this->assertSame([1301, 1400, null], $this->range('1297-14 ق'));
        $this->assertSame([505, 505, null], $this->range('450 - 505 قمری'));
        $this->assertSame(1107, Person::yearBeforeDash('1107ق- 12 قمری'));
        $this->assertNull(Person::yearBeforeDash('ق 12 قمری'));
        $this->assertNull(Person::yearBeforeDash('3 - 50 قمری'));
    }

    public function test_a_century_with_no_dash_is_the_century_of_the_life_and_gives_no_death_range()
    {
        // step 14.213
        foreach (['ق13 قمری' => 13, 'ق 12' => 12, 'قرن 8 قمری' => 8, 'قرن 10 ؟ قمری' => 10, '1307 ق 14 قمری' => 14, 'ق۱۳ قمری' => 13] as $raw => $century) {
            $this->assertSame([null, null, null], $this->range($raw), $raw);
            $this->assertSame($century, Person::lifeCentury($raw), $raw);
        }
        // after a dash the century is the death's, and a year is a year
        foreach (['- قرن 13 قمری', '1217ق-13 قمری', '- 13', '1223ق 13 قمری', '- 1241 قمری', null, ''] as $raw) {
            $this->assertNull(Person::lifeCentury($raw), (string) $raw);
        }
        // the middle of that century is the corpus's sorting number, not a death year; any other year is one
        $this->assertSame([null, null, null], $this->range('ق13 قمری', 1250));
        $this->assertSame([1262, 1262, null], $this->range('ق13 قمری', 1262));
        $this->assertSame([1001, 1100, null], $this->range('-قرن 11ق', 1050));
        $this->assertTrue(Person::isCenturyMiddle('ق13 قمری', 1250));
        $this->assertTrue(Person::isCenturyMiddle('-قرن 11ق', 1050));
        $this->assertFalse(Person::isCenturyMiddle('ق13 قمری', 1150));
        $this->assertFalse(Person::isCenturyMiddle('1188-1250 قمری', 1250));
        $this->assertFalse(Person::isCenturyMiddle(null, 1250));
    }

    public function test_solar_and_gregorian_life_dates_give_the_hijri_years_they_span()
    {
        // step 14.214
        $this->assertSame([1351, 1352, null], $this->range('1250 - 1312 شمسی'));
        $this->assertSame([1351, 1352, null], $this->range('1250 - 1312 شمسی', 1312));
        $this->assertSame([1352, 1353, null], $this->range('-1313ش'));
        $this->assertSame([1345, 1346, '؟'], $this->range('1211 - 1306 ؟ شمسی'));
        $this->assertSame([1386, 1387, null], $this->range('1884 - 1967 میلادی'));
        // the death in a solar century: the solar years 1301 to 1400
        $this->assertSame([1340, 1443, null], $this->range('1284-ق14 شمسی'));
        $this->assertSame([1340, 1443, null], $this->range('1270ق-14 شمسی'));
        // no dash: the century of the life, or a year the person was alive in
        foreach (['ق 14 شمسی' => 14, '1297ق 14 شمسی' => 14, '1272 شمسی' => 14, 'ق19 میلادی' => 13, '1913 میلادی' => 14] as $raw => $century) {
            $this->assertSame([null, null, null], $this->range($raw), $raw);
            $this->assertSame($century, Person::lifeCentury($raw), $raw);
        }
        // before the Hijra there is no Hijri year
        foreach (['427؟ - 347 ق.م', '283 - 306 قبل میلاد', 'ق 6 قبل میلاد', '129 - 199 میلادی', 'ق3 میلادی', '130 - 80 قبل هجرت'] as $raw) {
            $this->assertSame([null, null, null], $this->range($raw), $raw);
            $this->assertNull(Person::lifeCentury($raw), $raw);
        }
        $this->assertSame([null, null, null], $this->range('قمری'));
    }

    public function test_the_parts_of_life_dates_of_another_calendar()
    {
        $this->assertNull(Person::otherCalendarLife('1250 - 1312 قمری'));
        $this->assertNull(Person::otherCalendarLife('ق13 قمری'));
        $solar = Person::otherCalendarLife('1250 - 1312 شمسی');
        $this->assertSame([Person::SOLAR, 1287, [1351, 1352], 1933], [$solar['calendar'], $solar['birth'][0], $solar['death'], $solar['gregorian']]);
        $this->assertSame(['birth' => 1250, 'death' => 1312, 'year' => null, 'century' => null, 'hijri' => null], $solar['numbers']);
        // a year with «ق» is Hijri as it stands
        $mixed = Person::otherCalendarLife('1270ق-14 شمسی');
        $this->assertSame([[1270, 1270], [1340, 1443], 1270, null], [$mixed['birth'], $mixed['death'], $mixed['numbers']['hijri'], $mixed['gregorian']]);
        $open = Person::otherCalendarLife('1329- شمسی');
        $this->assertSame([1369, null, null], [$open['birth'][0], $open['death'], $open['life']]);
        $galen = Person::otherCalendarLife('129 - 199 میلادی');
        $this->assertSame([Person::GREGORIAN, null, null, 199], [$galen['calendar'], $galen['birth'], $galen['death'], $galen['gregorian']]);
        $plato = Person::otherCalendarLife('427؟ - 347 ق.م');
        $this->assertSame([Person::BEFORE, null, null, 347], [$plato['calendar'], $plato['death'], $plato['gregorian'], $plato['numbers']['death']]);
        $this->assertSame(306, Person::otherCalendarLife('283 - 306 قبل میلاد')['numbers']['death']);
    }

    public function test_a_death_year_stands_alone_without_life_dates()
    {
        $this->assertSame([898, 898, null], $this->range(null, 898));
        $this->assertSame([null, null, null], $this->range(null));
    }
}
