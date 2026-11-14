<?php

namespace Tests\Unit;

use App\Support\DateSpan;
use PHPUnit\Framework\TestCase;

/**
 * One date as two ends, each a range of years, with the meaning of the range between them (item 134, step 14.188)
 */
class DateSpanTest extends TestCase
{
    public function test_the_ends_of_each_kind_of_date()
    {
        // one year, and a range of no known kind: the start and the end may be anywhere in it
        $this->assertSame([[1052, 1052], [1052, 1052]], [(new DateSpan(1052, 1052))->start(), (new DateSpan(1052, 1052))->end()]);
        $unknown = new DateSpan(1084, 1107);
        $this->assertSame([[1084, 1107], [1084, 1107]], [$unknown->start(), $unknown->end()]);
        $this->assertFalse($unknown->isSpan());
        // one event at an unknown time
        $between = new DateSpan(1201, 1300, DateSpan::BETWEEN);
        $this->assertSame([[1201, 1300], [1201, 1300]], [$between->start(), $between->end()]);
        // a duration with exact ends
        $span = new DateSpan(1052, 1057, DateSpan::SPAN);
        $this->assertTrue($span->isSpan());
        $this->assertSame([[1052, 1052], [1057, 1057]], [$span->start(), $span->end()]);
        // a duration whose ends are ranges themselves
        $loose = new DateSpan(1000, 1030, DateSpan::SPAN, 1010, 1020);
        $this->assertSame([[1000, 1010], [1020, 1030]], [$loose->start(), $loose->end()]);
        // a duration with an open end
        $open = new DateSpan(1000, null, DateSpan::SPAN);
        $this->assertSame([[1000, 1000], [null, null]], [$open->start(), $open->end()]);
    }

    public function test_the_date_in_words()
    {
        $this->assertNull((new DateSpan(null, null))->text());
        $this->assertSame('1052', (new DateSpan(1052, 1052))->text());
        $this->assertSame('1084–1107', (new DateSpan(1084, 1107))->text());
        $this->assertSame('میان 1250 و 1270', (new DateSpan(1250, 1270, DateSpan::BETWEEN))->text());
        $this->assertSame('پیش از 1100', (new DateSpan(null, 1100, DateSpan::BETWEEN))->text());
        $this->assertSame('پس از 1100', (new DateSpan(1100, null))->text());
        $this->assertSame('از 1052 تا 1057', (new DateSpan(1052, 1057, DateSpan::SPAN))->text());
        $this->assertSame('از میان 1000 و 1010 تا میان 1020 و 1030', (new DateSpan(1000, 1030, DateSpan::SPAN, 1010, 1020))->text());
        $this->assertSame('از 1000 تا میان 1020 و 1030', (new DateSpan(1000, 1030, DateSpan::SPAN, null, 1020))->text());
        $this->assertSame('از 1000 به بعد', (new DateSpan(1000, null, DateSpan::SPAN))->text());
        $this->assertSame('تا 1030', (new DateSpan(null, 1030, DateSpan::SPAN))->text());
    }

    public function test_columns_under_a_prefix_and_back()
    {
        $span = new DateSpan(1000, 1030, DateSpan::SPAN, 1010, 1020, 'probable', 'گویا');
        $columns = $span->columns('stay');
        $this->assertSame(['stay_from' => 1000, 'stay_to' => 1030, 'stay_certainty' => 'probable', 'stay_hedge_raw' => 'گویا',
            'stay_kind' => 'span', 'stay_start_to' => 1010, 'stay_end_from' => 1020], $columns);
        $this->assertEquals($span, DateSpan::fromColumns($columns, 'stay'));
        // a DB row with strings, and a table that has only the two outer columns
        $this->assertEquals(new DateSpan(1201, 1300), DateSpan::fromColumns((object) ['copy_date_from' => '1201', 'copy_date_to' => '1300'], 'copy_date'));
    }
}
