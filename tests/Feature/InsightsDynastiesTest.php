<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Support\CopyPlaces;
use App\Support\Dynasties;
use App\Support\Insights;
use App\Support\InsightsScope;
use App\Support\KnownRulers;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** The dynasties of the statistics (backlog item 154, step 14.265). */
class InsightsDynastiesTest extends TestCase
{
    use DatabaseTransactions;

    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانۀ آزمایشی سلسله‌ها', 'city' => 'قم', 'slug' => 'insights-dynasties-' . uniqid()]);
    }

    private function work(string $title): Work
    {
        return Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'insights-dynasties-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function copy(Work $work, array $attributes = []): Manuscript
    {
        $copy = Manuscript::create($attributes + ['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        $copy->forceFill(['library_id' => $this->library->id])->save();

        return $copy;
    }

    private function figures(): array
    {
        return Insights::build(InsightsScope::fromArray(['library' => $this->library->id]));
    }

    public function test_a_city_is_under_the_dynasty_whose_territory_held_it_in_that_decade()
    {
        $this->assertSame('صفویان', Dynasties::of('اصفهان', 1040));
        $this->assertSame('گورکانیان هند (تیموریان هند)', Dynasties::of('لاهور', 1040));
        $this->assertSame('قاجاریان', Dynasties::of('تهران', 1280));
        $this->assertSame('عثمانی‌ها', Dynasties::of('نجف', 1280));
        $this->assertNull(Dynasties::of('اصفهان', 100));
        $this->assertNull(Dynasties::of('شهری که نیست', 1040));
        $this->assertSame([[1001, 1100, 'صفویان']], Dynasties::runs('اصفهان', 1001, 1100));
    }

    public function test_the_data_names_only_cities_of_the_map_and_every_court_is_a_dynasty_of_the_data()
    {
        $data = require database_path('data/dynasties.php');
        $names = array_column($data['dynasties'], 'name');

        $this->assertSame([], array_diff(array_keys($data['rule']), array_keys(CopyPlaces::cities())));
        foreach ($data['rule'] as $city => $runs) {
            foreach ($runs as $i => [$from, $to, $name]) {
                $this->assertLessThanOrEqual($to, $from, $city);
                $this->assertContains($name, $names, $city);
                $this->assertTrue($i === 0 || $from > $runs[$i - 1][1], "{$city}: the runs follow one another");
            }
        }
        foreach (KnownRulers::LIST as $ruler) {
            $this->assertContains($ruler['dynasty'], $names, $ruler['name']);
            $this->assertSame($ruler['dynasty'], Dynasties::courtOf($ruler['name']));
        }
        $this->assertNull(Dynasties::courtOf('کسی که فرمانروا نیست'));
    }

    public function test_copies_with_a_year_and_a_city_are_counted_under_their_dynasty()
    {
        $work = $this->work('اثر آزمایشی سلسله‌ها');
        $this->copy($work, ['copy_date_raw' => '1040ق', 'copy_place' => 'اصفهان']);
        $this->copy($work, ['copy_date_raw' => '1060ق', 'copy_place' => 'دارالسلطنه اصفهان']);
        $this->copy($work, ['copy_date_raw' => '1050ق', 'copy_place' => 'مشهد']);
        $this->copy($work, ['copy_date_raw' => '1040ق', 'copy_place' => 'لاهور']);
        // No dynasty: a century alone, a place that is no city of the map, no place, no date
        $this->copy($work, ['copy_date_raw' => 'قرن 11', 'copy_place' => 'اصفهان']);
        $this->copy($work, ['copy_date_raw' => '1040ق', 'copy_place' => 'ایران']);
        $this->copy($work, ['copy_date_raw' => '1040ق']);
        $this->copy($work, ['copy_place' => 'اصفهان']);

        $figures = $this->figures()['dynasties'];

        $this->assertSame(4, $figures['counted']);
        $this->assertSame(4, $figures['ruled']);
        $this->assertSame(50.0, $figures['coverage']);
        $this->assertSame(2, $figures['kinds']);
        $this->assertSame(['name' => 'صفویان', 'count' => 3, 'from' => 1040, 'to' => 1060,
            'cities' => [['name' => 'اصفهان', 'count' => 2], ['name' => 'مشهد', 'count' => 1]], 'city_kinds' => 2], $figures['items'][0]);
        $this->assertSame('گورکانیان هند (تیموریان هند)', $figures['items'][1]['name']);
        // The band under the decades: the busiest city of the century and who ruled it
        $this->assertSame(['city' => 'اصفهان', 'count' => 2, 'runs' => [[1001, 1100, 'صفویان']]], $figures['centuries'][11]);
    }

    public function test_dedications_and_patrons_are_counted_by_the_court_of_a_known_ruler()
    {
        $person = fn (string $name) => Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'insights-dynasties-' . uniqid()]);
        $shah = $person('عباس صفوی، شاه ایران');
        $second = $person('عباس صفوی دوم، شاه ایران');
        $other = $person('کسی از بزرگان آزمایشی');
        foreach ([[$shah, 2], [$second, 1], [$other, 1]] as [$to, $n]) {
            foreach (range(1, $n) as $i) {
                $work = $this->work("اثر تقدیمی {$to->id}-{$i}");
                $copy = $this->copy($work);
                WorkPerson::create(['work_id' => $work->id, 'person_id' => $to->id, 'name' => $to->name, 'role' => 'dedicatee', 'position' => 1]);
                ManuscriptPerson::create(['manuscript_id' => $copy->id, 'person_id' => $to->id, 'name' => $to->name, 'role' => 'patron', 'position' => 1]);
            }
        }

        $figures = $this->figures();
        $court = ['name' => 'صفویان', 'count' => 3, 'people' => 2, 'first' => 'عباس صفوی، شاه ایران'];

        $this->assertSame([$court], $figures['dedications']['courts']);
        $this->assertSame([$court], $figures['patrons']['courts']);
    }

    public function test_the_time_page_shows_the_dynasties_and_the_band()
    {
        $work = $this->work('اثر آزمایشی صفحۀ سلسله‌ها');
        foreach (range(1, 12) as $i) {
            $this->copy($work, ['copy_date_raw' => (1040 + $i) . 'ق', 'copy_place' => 'اصفهان']);
        }

        $this->get(route('insights.topic', ['topic' => 'time', 'library' => $this->library->id]))->assertOk()
            ->assertSee('نسخه‌ها در روزگار هر سلسله')
            ->assertSee('صفویان')
            ->assertSee('data-insights-band', false)
            ->assertSee('data-insights-table="copies-by-dynasty"', false)
            ->assertSee('بیشترین نسخه از روزگار صفویان است: ۱۲ نسخه');
    }

    public function test_the_dynasties_are_rows_of_a_chart_and_their_capitals_are_on_the_map()
    {
        $work = $this->work('اثر آزمایشی نمودار ردیفی');
        foreach (range(1, 12) as $i) {
            $this->copy($work, ['copy_date_raw' => (1040 + $i) . 'ق', 'copy_place' => 'اصفهان']);
        }
        $this->copy($work, ['copy_date_raw' => '1040ق', 'copy_place' => 'لاهور']);

        // Every dynasty with a copy has its count, beyond the largest ones the list shows
        config(['insights.dynasties_shown' => 1]);
        $figures = $this->figures()['dynasties'];
        $this->assertCount(1, $figures['items']);
        $this->assertSame(['صفویان' => 12, 'گورکانیان هند (تیموریان هند)' => 1], $figures['counts']);

        $capitals = Dynasties::capitals();
        $names = array_column(Dynasties::all(), 'name');
        $this->assertNotEmpty($capitals);
        foreach ($capitals as $capital) {
            $this->assertSame(['name', 'dynasty', 'lat', 'lon', 'from', 'to'], array_keys($capital));
            $this->assertLessThanOrEqual($capital['to'], $capital['from'], $capital['name']);
        }
        $this->assertNotEmpty(array_intersect(array_column($capitals, 'dynasty'), $names));
        $this->assertContains('ایران', Dynasties::regions());

        $this->get(route('insights.topic', ['topic' => 'time', 'library' => $this->library->id]))->assertOk()
            ->assertSee('data-insights-dynasty-rows', false)
            ->assertSee('x-data="insightsDynasties(', false);
        $this->get(route('insights.topic', ['topic' => 'places', 'library' => $this->library->id]))->assertOk()
            ->assertSee('capitals', false)
            ->assertSee('پایتخت');
        // One chunk of ECharts for the two charts, never in the site's own script
        $this->assertStringContainsString("import('./echarts-map')", file_get_contents(resource_path('js/insights-dynasties.js')));
    }
}
