<?php

namespace Tests\Unit;

use App\Models\PersonPeriod;
use PHPUnit\Framework\TestCase;

/**
 * The years of a period's form as the columns of a DateSpan, and back (item 139, step 14.218)
 */
class PersonPeriodDateTest extends TestCase
{
    private function columns(array $input): array
    {
        $c = PersonPeriod::dateColumns($input);

        return isset($c['error']) ? ['error'] : [$c['period_from'], $c['period_start_to'], $c['period_end_from'], $c['period_to'], $c['period_kind']];
    }

    public function test_the_forms_years_become_the_columns_of_the_date()
    {
        // a duration with exact ends, with uncertain ends, and with an open end
        $this->assertSame([1000, null, null, 1030, 'span'], $this->columns(['mode' => 'span', 'start' => 1000, 'end' => 1030]));
        $this->assertSame([1000, 1010, 1020, 1030, 'span'], $this->columns(['mode' => 'span', 'start' => 1000, 'start_to' => 1010, 'end' => 1020, 'end_to' => 1030]));
        $this->assertSame([1000, null, 1020, 1030, 'span'], $this->columns(['mode' => 'span', 'start' => 1000, 'end' => 1020, 'end_to' => 1030]));
        $this->assertSame([1000, null, null, null, 'span'], $this->columns(['mode' => 'span', 'start' => 1000]));
        $this->assertSame([null, null, null, 1030, 'span'], $this->columns(['mode' => 'span', 'end' => 1030]));
        // one year, however it is entered
        $this->assertSame([1052, null, null, 1052, null], $this->columns(['mode' => 'point', 'start' => 1052, 'end' => 1070]));
        $this->assertSame([1052, null, null, 1052, null], $this->columns(['mode' => 'span', 'start' => 1052, 'end' => 1052]));
        // one event at an unknown time
        $this->assertSame([1000, null, null, 1030, 'between'], $this->columns(['mode' => 'between', 'start' => 1000, 'end' => 1030]));
        // no year, years out of order, a mode without its years
        foreach ([['mode' => 'span'], ['mode' => 'span', 'start' => 1030, 'end' => 1000], ['mode' => 'span', 'start' => 1000, 'start_to' => 1040, 'end' => 1030],
            ['mode' => 'point', 'end' => 1000], ['mode' => 'between', 'start' => 1000]] as $bad) {
            $this->assertSame(['error'], $this->columns($bad), json_encode($bad));
        }
    }

    public function test_the_stored_date_fills_the_form_again()
    {
        foreach ([['mode' => 'span', 'start' => 1000, 'start_to' => 1010, 'end' => 1020, 'end_to' => 1030], ['mode' => 'span', 'start' => 1000, 'start_to' => null, 'end' => 1030, 'end_to' => null],
            ['mode' => 'between', 'start' => 1000, 'start_to' => null, 'end' => 1030, 'end_to' => null], ['mode' => 'point', 'start' => 1052, 'start_to' => null, 'end' => null, 'end_to' => null]] as $input) {
            $period = (new PersonPeriod)->forceFill(PersonPeriod::dateColumns($input));
            $this->assertSame($input, $period->dateInput());
        }
    }
}
