<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Support\Insights;
use App\Support\InsightsScope;
use App\Support\InsightsTables;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** The map of the statistics: decades, people, territories (backlog item 137, step 14.275). */
class InsightsMapTest extends TestCase
{
    use DatabaseTransactions;

    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانۀ آزمایشی نقشه', 'city' => 'قم', 'slug' => 'insights-map-' . uniqid()]);
    }

    private function person(): Person
    {
        $name = 'شخص آزمایشی نقشه ' . uniqid();

        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'insights-map-' . uniqid()]);
    }

    private function work(array $attributes = []): Work
    {
        $title = 'اثر آزمایشی نقشه ' . uniqid();
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'insights-map-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $attributes && $work->forceFill($attributes)->save();

        return $work;
    }

    private function copy(Work $work, array $attributes = []): Manuscript
    {
        $copy = Manuscript::create(array_diff_key($attributes, ['scribe_id' => 1]) + ['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        $copy->forceFill(['library_id' => $this->library->id] + array_intersect_key($attributes, ['scribe_id' => 1]))->save();

        return $copy;
    }

    private function places(): array
    {
        return Insights::build(InsightsScope::fromArray(['library' => $this->library->id]))['places'];
    }

    public function test_a_city_has_its_copies_by_the_decade_their_date_ends_in()
    {
        $work = $this->work();
        $this->copy($work, ['copy_date_raw' => '1040ق', 'copy_place' => 'اصفهان']);
        $this->copy($work, ['copy_date_raw' => '1041ق', 'copy_place' => 'دارالسلطنه اصفهان']);
        $this->copy($work, ['copy_date_raw' => '1048ق', 'copy_place' => 'اصفهان']);
        // A copy of the century alone is «by a year» only when the century has passed
        $this->copy($work, ['copy_date_raw' => 'قرن 11', 'copy_place' => 'اصفهان']);
        $this->copy($work, ['copy_place' => 'اصفهان']);

        $city = collect($this->places()['cities'])->firstWhere('name', 'اصفهان');

        $this->assertSame(5, $city['count']);
        $this->assertSame([1040 => 1, 1050 => 2, 1100 => 1], $city['decades']);
        $this->assertEqualsWithDelta(32.65, $city['lat'], 0.2);
        $this->assertEqualsWithDelta(51.67, $city['lon'], 0.2);
    }

    public function test_scribes_and_authors_are_counted_once_per_city_at_their_first_decade_there()
    {
        [$first, $second, $author] = [$this->person(), $this->person(), $this->person()];
        $work = $this->work(['author_id' => $author->id, 'composition_place' => 'مشهد مقدس', 'composition_date_raw' => '1100ق']);
        $this->copy($work, ['copy_date_raw' => '1061ق', 'copy_place' => 'اصفهان', 'scribe_id' => $first->id]);
        $this->copy($work, ['copy_date_raw' => '1045ق', 'copy_place' => 'اصفهان', 'scribe_id' => $first->id]);
        $this->copy($work, ['copy_date_raw' => '1070ق', 'copy_place' => 'مشهد', 'scribe_id' => $first->id]);
        // A scribe with no dated copy in the city: in its whole count, in no decade
        $this->copy($work, ['copy_place' => 'اصفهان', 'scribe_id' => $second->id]);
        $this->copy($work, ['copy_date_raw' => '1050ق', 'copy_place' => 'اصفهان']);

        $places = $this->places();
        $cities = collect($places['cities'])->keyBy('name');

        $this->assertSame(2, $cities['اصفهان']['scribes']);
        $this->assertSame([1050 => 1], $cities['اصفهان']['scribe_decades']);
        $this->assertSame(1, $cities['مشهد']['scribes']);
        $this->assertSame([1070 => 1], $cities['مشهد']['scribe_decades']);
        $this->assertSame(2, $places['scribes_mapped']);
        $this->assertSame(1, $cities['مشهد']['authors']);
        $this->assertSame([1100 => 1], $cities['مشهد']['author_decades']);
        $this->assertSame([1100 => 1], $cities['مشهد']['work_decades']);
        $this->assertSame(0, $cities['اصفهان']['authors']);
        $this->assertSame(1, $places['authors_mapped']);

        $table = collect(InsightsTables::for(Insights::build(InsightsScope::fromArray(['library' => $this->library->id])))['places'])->firstWhere('file', 'people-places');
        $this->assertSame([['اصفهان', 2, 0, 4, 0], ['مشهد', 1, 1, 1, 1]], $table['rows']);
    }

    public function test_the_places_page_carries_the_map_its_data_and_the_still_map()
    {
        $work = $this->work();
        foreach (range(1, 12) as $i) {
            $this->copy($work, ['copy_date_raw' => (1040 + $i) . 'ق', 'copy_place' => 'اصفهان']);
        }

        $this->get(route('insights.topic', ['topic' => 'places', 'library' => $this->library->id]))->assertOk()
            ->assertSee('data-insights-map', false)
            ->assertSee('x-data="insightsMap(', false)
            ->assertSee('basemap.json', false)
            ->assertSee('__YEAR__.json', false)
            ->assertSee('نسخه‌ها (محل کتابت)')
            ->assertSee('کاتبان')
            ->assertSee('تا این سال')
            // The still map, for the time before the script and for a reader without it
            ->assertSee('<circle cx="', false)
            ->assertSee('data-insights-table="copy-places"', false);
    }

    public function test_the_base_map_and_a_territory_file_of_every_decade_of_the_slider_are_published()
    {
        $base = json_decode(file_get_contents(public_path('data/insights/basemap.json')), true);
        $this->assertSame('FeatureCollection', $base['type']);
        $this->assertNotEmpty($base['features']);

        $last = config('insights.centuries')[1] * 100 - 50;
        for ($year = 300; $year <= $last; $year += 10) {
            $this->assertFileExists(public_path('data/insights/territories/' . ($year - 5) . '.json'), (string) $year);
        }
        $lands = json_decode(file_get_contents(public_path('data/insights/territories/1035.json')), true)['features'];
        $this->assertContains('صفویان', array_column(array_column($lands, 'properties'), 'name'));
        $this->assertSame(['name', 'region', 'span'], array_keys($lands[0]['properties']));

        // ECharts is a chunk of its own, loaded by the map alone
        $this->assertStringContainsString("import('./echarts-map')", file_get_contents(resource_path('js/insights-map.js')));
        $this->assertStringNotContainsString('echarts', file_get_contents(resource_path('js/app.js')));
    }
}
