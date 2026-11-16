<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Solar, Gregorian and pre-Christian years that were stored as Hijri years are converted, or emptied where there is
 * no Hijri year (step 14.214)
 */
class FixCalendarDeathsCommandTest extends TestCase
{
    private array $works = [];

    private int $ordinal = 0;

    /** A person with the columns as the corpus left them, and the life dates their work carries in the decision file. */
    private function person(string $name, array $cols, ?string $text, ?string $second = null): int
    {
        $id = DB::table('people')->insertGetId(['name' => $name, 'normalized_name' => $name, 'slug' => 'calendar-' . md5($name)] + $cols);
        foreach (array_filter([$text, $second]) as $raw) {
            $n = ++$this->ordinal;
            Work::create(['primary_title' => "رسالۀ تقویم {$n}", 'clean_title' => "رسالۀ تقویم {$n}", 'slug' => "test-calendar-v9-{$n}", 'volume_number' => 9, 'page_start' => $n, 'author_id' => $id]);
            $this->works[] = ['key' => ['vol' => 9, 'ordinal' => $n], 'title' => "رسالۀ تقویم {$n}", 'provenance' => ['page_start' => $n], 'author' => ['name' => $name, 'death_raw' => $raw]];
        }

        return $id;
    }

    private function file(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'calendar');
        file_put_contents($file, json_encode(['works' => $this->works, 'peers' => []], JSON_UNESCAPED_UNICODE));

        return $file;
    }

    private function cols(int $id): array
    {
        return array_values((array) DB::table('people')->where('id', $id)
            ->first(['life_dates_raw', 'birth_year_hijri', 'death_year_hijri', 'century_hijri', 'death_year_gregorian', 'death_from', 'death_to', 'death_kind']));
    }

    public function test_years_of_another_calendar_are_converted_only_with_apply_and_a_run_can_be_repeated()
    {
        $as = fn (int $year, array $more = []) => ['death_year_hijri' => $year, 'century_hijri' => (int) ceil($year / 100), 'death_from' => $year, 'death_to' => $year] + $more;
        $solar = $this->person('سپهر آزمایشی', $as(1312, ['life_dates_raw' => '1250 - 1312', 'death_year_gregorian' => 1895]), '1250 - 1312 شمسی');
        // the catalogue writes «قمری» in one work and «شمسی» in another, with the same numbers
        $bothMarks = $this->person('فروغی آزمایشی', $as(1321, ['life_dates_raw' => '1254 - 1321 قمری', 'birth_year_hijri' => 1254]), '1254 - 1321 قمری', '1254-1321 شمسی');
        $doubtful = $this->person('تختی آزمایشی', $as(1306), '1211 - 1306 ؟ شمسی');
        $loneYear = $this->person('طهرانی آزمایشی', $as(1272), '1272 شمسی');
        $solarCentury = $this->person('شفیعی آزمایشی', ['life_dates_raw' => 'ق 14', 'death_year_hijri' => 1350, 'century_hijri' => 14, 'death_from' => 1301, 'death_to' => 1400, 'death_kind' => 'between'], 'ق 14 شمسی');
        $hijriBirth = $this->person('چهاردهی آزمایشی', $as(1297), '1297ق 14 شمسی');
        $openEnd = $this->person('هروی آزمایشی', $as(1329), '1329- شمسی');
        $birthAsDeath = $this->person('جمال‌زاده آزمایشی', $as(1270, ['life_dates_raw' => '1270']), '1270 - 1376 شمسی');
        $gregorian = $this->person('پولاک آزمایشی', [], '1818 - 1891 میلادی');
        $galen = $this->person('جالینوس آزمایشی', $as(199, ['death_year_gregorian' => 815]), '129 - 199 میلادی');
        $plato = $this->person('افلاطون آزمایشی', $as(347, ['death_year_gregorian' => 958]), '427؟ - 347 ق.م');
        $euclid = $this->person('اقلیدس آزمایشی', $as(306), '283 - 306 قبل میلاد');
        $beforeCentury = $this->person('دانیال آزمایشی', $as(550), 'ق 6 قبل میلاد');
        // left alone: the site's own Hijri dates, a «Gregorian» year that is a solar or a Hijri one, a solar year that is no solar year
        $hijri = $this->person('شهرستانی آزمایشی', $as(1386, ['life_dates_raw' => '1301 - 1386 قمری', 'birth_year_hijri' => 1301]), '1884 - 1967 میلادی');
        $mislabelled = $this->person('فرخ آزمایشی', $as(1352), '1265-1352 میلادی');
        $notSolar = $this->person('عنایت خان آزمایشی', $as(1081), '- 1081 شمسی');
        $file = $this->file();

        $this->artisan('fahares:fix-calendar-deaths', ['--file' => $file])
            ->expectsOutputToContain('Dry run, nothing written: 8 solar, 2 Gregorian, 3 before the Hijra, 0 already right, 3 left alone')->assertSuccessful();
        $this->assertSame(['1250 - 1312', null, 1312, 14, 1895, 1312, 1312, null], $this->cols($solar));

        $this->artisan('fahares:fix-calendar-deaths', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $this->assertSame(['1250 - 1312 شمسی', 1287, 1351, 14, 1933, 1351, 1352, 'between'], $this->cols($solar));
        $this->assertSame(['1254-1321 شمسی', 1292, 1361, 14, 1942, 1361, 1362, 'between'], $this->cols($bothMarks));
        $this->assertSame(['1211 - 1306 ؟ شمسی', 1247, 1345, 14, 1927, 1345, 1346, 'between'], $this->cols($doubtful));
        $this->assertSame('doubtful', DB::table('people')->where('id', $doubtful)->value('death_certainty'));
        // a lone year is neither a birth nor a death: the century of the life
        $this->assertSame(['1272 شمسی', null, null, 14, null, null, null, null], $this->cols($loneYear));
        $this->assertSame(['ق 14 شمسی', null, null, 14, null, null, null, null], $this->cols($solarCentury));
        $this->assertSame(['1297ق 14 شمسی', null, null, 14, null, null, null, null], $this->cols($hijriBirth));
        $this->assertSame(['1329- شمسی', 1369, null, 14, null, null, null, null], $this->cols($openEnd));
        $this->assertSame(['1270 - 1376 شمسی', 1308, 1417, 15, 1997, 1417, 1418, 'between'], $this->cols($birthAsDeath));
        $this->assertSame(['1818 - 1891 میلادی', 1233, 1308, 14, 1891, 1308, 1309, 'between'], $this->cols($gregorian));
        // before the Hijra: no Hijri year and no century; a Gregorian death year stays as it is
        $this->assertSame(['129 - 199 میلادی', null, null, null, 199, null, null, null], $this->cols($galen));
        $this->assertSame(['427؟ - 347 ق.م', null, null, null, null, null, null, null], $this->cols($plato));
        $this->assertSame(['283 - 306 قبل میلاد', null, null, null, null, null, null, null], $this->cols($euclid));
        $this->assertSame(['ق 6 قبل میلاد', null, null, null, null, null, null, null], $this->cols($beforeCentury));
        $this->assertSame(['1301 - 1386 قمری', 1301, 1386, 14, null, 1386, 1386, null], $this->cols($hijri));
        $this->assertSame([null, null, 1352, 14, null, 1352, 1352, null], $this->cols($mislabelled));
        $this->assertSame([null, null, 1081, 11, null, 1081, 1081, null], $this->cols($notSolar));

        $this->artisan('fahares:fix-calendar-deaths', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('Written: 0 solar, 0 Gregorian, 0 before the Hijra, 13 already right, 3 left alone')->assertSuccessful();
        // a saved person keeps the converted range
        Person::find($solar)->update(['bio_notes' => 'یادداشت']);
        Person::find($solar)->update(['death_year_hijri' => 1352]);
        $this->assertSame([1351, 1352], array_slice($this->cols($solar), 5, 2));
    }
}
