<?php

namespace Tests\Feature;

use App\Models\Person;
use Tests\TestCase;

/**
 * A death year that is the year a person was alive in is emptied, and an empty one is filled from life dates that
 * give the year (step 14.179)
 */
class FillDeathYearsCommandTest extends TestCase
{
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
