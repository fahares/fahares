<?php

namespace Tests\Feature;

use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A lone year, the year before an open dash and the middle of a century are no death years, and the «قمری» dates of
 * two people are solar (step 14.225)
 */
class FixLifeYearsCommandTest extends TestCase
{
    private function cols(int $id): array
    {
        return array_values((array) DB::table('people')->where('id', $id)->first(['life_dates_raw', 'birth_year_hijri', 'death_year_hijri', 'century_hijri', 'death_from', 'death_to']));
    }

    public function test_saving_a_person_takes_a_lone_year_and_an_open_dash_as_no_death()
    {
        $lone = Person::create(['name' => 'ذخیرۀ سال تنها آزمایشی', 'normalized_name' => 'ذخیرۀ سال تنها آزمایشی', 'slug' => 'years-lone', 'life_dates_raw' => '1360 قمری', 'death_year_hijri' => 1360]);
        $this->assertSame(['1360 قمری', null, null, 14, null, null], $this->cols($lone->id));
        $this->assertSame('سدۀ ۱۴ ق', $lone->fresh()->life_span);

        $open = Person::create(['name' => 'ذخیرۀ خط تیرۀ باز آزمایشی', 'normalized_name' => 'ذخیرۀ خط تیرۀ باز آزمایشی', 'slug' => 'years-open', 'life_dates_raw' => '1357 - قمری', 'death_year_hijri' => 1357]);
        $this->assertSame(['1357 - قمری', 1357, null, 14, null, null], $this->cols($open->id));
        $this->assertSame('ز. ۱۳۵۷ ق', $open->fresh()->life_span);

        // a real death year beside a lone year stands
        $lone->update(['death_year_hijri' => 1420]);
        $this->assertSame(['1360 قمری', null, 1420, 15, 1420, 1420], $this->cols($lone->id));
        $this->assertSame(['year' => 1360, 'birth' => false], Person::lifeYear('1360 قمری'));
        $this->assertSame(['year' => 1254, 'birth' => true], Person::lifeYear('1254 ق-'));
        $this->assertNull(Person::lifeYear('- 1325 قمری'));
        $this->assertNull(Person::lifeYear('1272 شمسی'));
    }
}
