<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A lone year, the year before an open dash and the middle of a century are no death years, and the «قمری» dates of
 * two people are solar (step 14.225)
 */
class FixLifeYearsCommandTest extends TestCase
{
    private array $works = [];

    private int $ordinal = 0;

    /** A person as the corpus left them, with the life dates their works carry in the decision file; the last work is returned. */
    private function person(string $name, array $cols, string ...$texts): int
    {
        $id = DB::table('people')->insertGetId(['name' => $name, 'normalized_name' => $name, 'slug' => 'years-' . md5($name)] + $cols);
        foreach ($texts as $raw) {
            $n = ++$this->ordinal;
            Work::create(['primary_title' => "رسالۀ سال {$n}", 'clean_title' => "رسالۀ سال {$n}", 'slug' => "test-years-v8-{$n}", 'volume_number' => 8, 'page_start' => $n, 'author_id' => $id]);
            $this->works[] = ['key' => ['vol' => 8, 'ordinal' => $n], 'title' => "رسالۀ سال {$n}", 'provenance' => ['page_start' => $n], 'author' => ['name' => $name, 'death_raw' => $raw]];
        }

        return $id;
    }

    /** A work of the person composed in that year. */
    private function composed(int $personId, int $year): void
    {
        $work = Work::create(['primary_title' => "اثر تاریخ‌دار {$personId}-{$year}", 'clean_title' => 'اثر تاریخ‌دار', 'slug' => 'years-dated-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        DB::table('works')->where('id', $work->id)->update(['composition_date_from' => $year, 'composition_date_to' => $year]);
        DB::table('work_people')->insert(['work_id' => $work->id, 'person_id' => $personId, 'role' => 'author', 'position' => 0]);
    }

    private function cols(int $id): array
    {
        return array_values((array) DB::table('people')->where('id', $id)->first(['life_dates_raw', 'birth_year_hijri', 'death_year_hijri', 'century_hijri', 'death_from', 'death_to']));
    }

    public function test_the_years_are_mended_only_with_apply_and_a_run_can_be_repeated()
    {
        $as = fn (int $year, ?string $raw = null) => ['life_dates_raw' => $raw, 'death_year_hijri' => $year, 'century_hijri' => (int) ceil($year / 100), 'death_from' => $year, 'death_to' => $year];
        // another work writes the same year with a dash: a death, a birth with a death, an open birth
        $death = $this->person('اشکوری آزمایشی', $as(1325, '1325 قمری'), '1325 قمری', '- 1325 قمری', '- 1325 قمری');
        $both = $this->person('صاحب جواهر آزمایشی', $as(1270, '1270 قمری'), '1270 قمری', '1270 - 1365 قمری');
        $openText = $this->person('علی بخش آزمایشی', $as(1254, '1254 قمری'), '1254 قمری', '1254- قمری');
        // a work 36 years after the year: the birth year, when the year is marked Hijri
        $birth = $this->person('رئیس العلماء آزمایشی', $as(1294, '1294 قمری'), '1294 قمری');
        $this->composed($birth, 1330);
        $unmarked = $this->person('گلچین آزمایشی', $as(1295, '1295'), '1295');
        $this->composed($unmarked, 1383);
        // a work before the year: not the birth, and no proof of the death
        $before = $this->person('سبزواری آزمایشی', $as(1150, '1150؟ قمری'), '1150؟ قمری');
        $this->composed($before, 1138);
        $alone = $this->person('تاریخ ترجمه: رمضان', $as(1292, '1292ق'), '1292ق');
        $open = $this->person('حکیمی آزمایشی', $as(1357), '1357 - قمری');
        $middle = $this->person('خوئی آزمایشی', $as(1250), 'ق13 قمری');
        $middleNoText = $this->person('قلندرشاه آزمایشی', $as(1250));
        $solar = $this->person('عبرت نائینی، محمد علی', $as(1321, '1245؟ - 1321 قمری'), '1245؟ - 1321 قمری');
        $solarDeath = $this->person('لاهیجی حائری، علی بن مهدی', $as(1306, '-1306 قمری'), '-1306 قمری');
        // the same name with other life dates is someone else
        $namesake = $this->person('شعار، یوسف', $as(1310, '- 1310 قمری'), '- 1310 قمری');
        // left alone: a death after a dash, a death year with no life dates that is no middle of a century
        $dashed = $this->person('نائینی آزمایشی', $as(1250, '1188-1250 قمری'), '1188-1250 قمری');
        $plain = $this->person('ساده آزمایشی', $as(1241));
        $file = tempnam(sys_get_temp_dir(), 'years');
        file_put_contents($file, json_encode(['works' => $this->works, 'peers' => []], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:fix-life-years', ['--file' => $file])
            ->expectsOutputToContain('Dry run, nothing written: 3 fuller texts, 1 birth years, 3 years alive, 1 open dashes, 2 mid-century years, 2 solar, 0 already right')->assertSuccessful();
        $this->assertSame(['1294 قمری', null, 1294, 13, 1294, 1294], $this->cols($birth));

        $this->artisan('fahares:fix-life-years', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $this->assertSame(['- 1325 قمری', null, 1325, 14, 1325, 1325], $this->cols($death));
        $this->assertSame(['1270 - 1365 قمری', 1270, 1365, 14, 1365, 1365], $this->cols($both));
        $this->assertSame(['1254- قمری', 1254, null, 13, null, null], $this->cols($openText));
        $this->assertSame(['1294 قمری', 1294, null, 13, null, null], $this->cols($birth));
        $this->assertSame(['1295', null, null, 13, null, null], $this->cols($unmarked));
        $this->assertSame(['1150؟ قمری', null, null, 12, null, null], $this->cols($before));
        $this->assertSame(['1292ق', null, null, 13, null, null], $this->cols($alone));
        $this->assertSame(['1357 - قمری', 1357, null, 14, null, null], $this->cols($open));
        $this->assertSame(['ق13 قمری', null, null, 13, null, null], $this->cols($middle));
        $this->assertSame([null, null, null, 13, null, null], $this->cols($middleNoText));
        $this->assertSame(['1245؟ - 1321 شمسی', 1282, 1361, 14, 1361, 1362], $this->cols($solar));
        $this->assertSame(['-1306 شمسی', null, 1345, 14, 1345, 1346], $this->cols($solarDeath));
        $this->assertSame(['- 1310 قمری', null, 1310, 14, 1310, 1310], $this->cols($namesake));
        $this->assertSame(['1188-1250 قمری', null, 1250, 13, 1250, 1250], $this->cols($dashed));
        $this->assertSame([null, null, 1241, 13, 1241, 1241], $this->cols($plain));

        $this->artisan('fahares:fix-life-years', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('Written: 0 fuller texts, 0 birth years, 0 years alive, 0 open dashes, 0 mid-century years, 0 solar')->assertSuccessful();
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
