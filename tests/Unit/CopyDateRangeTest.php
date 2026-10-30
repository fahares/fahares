<?php

namespace Tests\Unit;

use App\Support\CopyDateRange;
use PHPUnit\Framework\TestCase;

/**
 * A copy's date as a range of Hijri years, with the catalogue's hedge word (backlog item 96)
 */
class CopyDateRangeTest extends TestCase
{
    private function range(string $raw): array
    {
        $r = CopyDateRange::parse($raw);

        return [$r['from'], $r['to']];
    }

    public function test_centuries_and_their_parts()
    {
        $this->assertSame([1201, 1300], $this->range('قرن 13'));
        $this->assertSame([1101, 1300], $this->range('قرن 12 و 13'));
        $this->assertSame([1101, 1300], $this->range('قرن 12-13'));
        $this->assertSame([1201, 1233], $this->range('اوایل قرن 13'));
        $this->assertSame([1234, 1266], $this->range('اواسط قرن 13'));
        $this->assertSame([1167, 1200], $this->range('اواخر قرن 12'));
        $this->assertSame([1051, 1100], $this->range('نیمه دوم قرن 11'));
        $this->assertSame([1201, 1300], $this->range('قرن ۱۳'));
    }

    public function test_years_spans_and_open_ends()
    {
        $this->assertSame([1246, 1246], $this->range('3 رمضان 1246ق'));
        $this->assertSame([1082, 1083], $this->range('1082 و 1083ق'));
        $this->assertSame([1034, 1052], $this->range('1034 تا 1052ق'));
        $this->assertSame([1081, 1091], $this->range('حدود 1086ق'));
        $this->assertSame([null, 1100], $this->range('پیش از 1100'));
        $this->assertSame([1100, null], $this->range('پس از 1100'));
    }

    public function test_the_date_is_the_first_clause_with_a_year_and_other_calendars_are_left_out()
    {
        // The year after a «،» inside the date, and not the year of the copy it was made from
        $this->assertSame([1268, 1268], $this->range('18 ربیع الثانی، 1268'));
        $this->assertSame([1122, 1122], $this->range('1122، از روی نسخه‌ای در 616'));
        $this->assertSame([null, null], $this->range('1250م'));
        $this->assertSame([null, null], $this->range('نزدیک عصر مؤلف'));
    }

    public function test_hedge_words_and_their_certainty()
    {
        $this->assertSame(['probable', 'محتملاً'], array_values(array_intersect_key(CopyDateRange::parse('محتملاً قرن 11'), ['hedge' => 0, 'certainty' => 0])));
        $this->assertSame(['doubtful', '؟'], array_values(array_intersect_key(CopyDateRange::parse('1000 (؟)'), ['hedge' => 0, 'certainty' => 0])));
        $this->assertSame([null, null], array_values(array_intersect_key(CopyDateRange::parse('قرن 13'), ['hedge' => 0, 'certainty' => 0])));
    }
}
