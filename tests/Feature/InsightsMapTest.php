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
        $this->assertSame([['اصفهان', 2, 0, 0, 0, 0, 0, 4, 0], ['مشهد', 1, 1, 0, 0, 0, 0, 1, 1]], $table['rows']);
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
            // Its dots carry their exact figure for the shared box, as the marks of the other charts (backlog item 156)
            ->assertSee('data-tip="اصفهان: ۱۲ نسخه"', false)
            ->assertSee('data-insights-table="copy-places"', false);
    }

    public function test_the_counts_of_the_cities_come_apart_from_the_page_in_its_scope()
    {
        $work = $this->work();
        foreach (range(1, 12) as $i) {
            $this->copy($work, ['copy_date_raw' => (1040 + $i) . 'ق', 'copy_place' => 'اصفهان']);
        }
        $scope = ['library' => $this->library->id];

        // The page names where the data is, and has the cities of the first layer as a list, but not their decades
        $page = $this->get(route('insights.topic', ['topic' => 'places'] + $scope))->assertOk()
            ->assertSee('map-data?library', false)
            ->assertSee('data-insights-still-cities', false)
            ->assertSee('data-insights-map-focus', false)
            ->getContent();
        $this->assertMatchesRegularExpression('/map-data\?library\D{1,7}' . $this->library->id . '\D/', $page);
        $this->assertStringNotContainsString('[1050,', $page);

        $cities = collect($this->getJson(route('insights.map', $scope))->assertOk()->assertHeader('Cache-Control', 'max-age=600, private')->json('cities'))->keyBy('n');
        $this->assertSame(['n', 'lat', 'lon', 't', 'd', 'w', 'wd', 's', 'sd', 'a', 'ad', 'o', 'od', 'b', 'bd', 'x', 'xd', 'r', 'rd'], array_keys($cities['اصفهان']));
        // Ten copies of 1041–1050 and two of 1051–1060, by the year their decade ends in
        $this->assertSame([12, [[1050, 10], [1060, 2]]], [$cities['اصفهان']['t'], $cities['اصفهان']['d']]);
        // Another scope has other counts
        $this->assertSame(0, collect($this->getJson(route('insights.map', ['library' => Library::create(['name' => 'کتابخانۀ دیگر نقشه', 'city' => 'قم', 'slug' => 'insights-map-' . uniqid()])->id]))->json('cities'))->sum('t'));
    }

    public function test_the_map_opens_at_the_decade_of_the_middle_of_a_rule()
    {
        // The decade is named by the year it ends in, inside the years of the slider
        $this->assertSame([300, 1030, Insights::mapYears()[1]], [Insights::mapYearOf(41, 132), Insights::mapYearOf(907, 1135), Insights::mapYearOf(1400, 1600)]);
        $script = file_get_contents(resource_path('js/insights-map.js'));
        $this->assertStringContainsString("asked.get('map_year')", $script);
        $this->assertStringContainsString("asked.get('map_at')", $script);
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

    public function test_the_people_of_a_scope_are_placed_by_their_nisba_and_their_lives()
    {
        $person = fn (string $name, array $more = []) => Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'insights-map-' . uniqid()] + $more);
        $author = $person('حسینی اصفهانی، محمد بن علی', ['death_year_hijri' => 1095, 'death_from' => 1095, 'death_to' => 1095]);
        $scribe = $person('محمد حسین بن علی تفرشی نجفی');
        $outside = $person('کسی از شیراز، شیرازی');
        $work = $this->work(['author_id' => $author->id]);
        $this->copy($work, ['copy_date_raw' => '1061ق', 'copy_place' => 'اصفهان', 'scribe_id' => $scribe->id]);

        $code = 'insights-map-' . uniqid();
        $source = \App\Models\Source::create(['code' => $code, 'kind' => \App\Models\Source::KIND_BIOGRAPHICAL, 'rank' => 30, 'title' => 'الأعلام', 'short_name' => 'الأعلام']);
        $volume = \App\Models\SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1, 'facsimile_code' => $code]);
        $life = function (Person $of, string $text, array $more = []) use ($source, $volume) {
            $item = \App\Models\SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 1, 'item_key' => uniqid(),
                'position' => 1, 'kind' => 'entry', 'page_start' => 10, 'page_end' => 10, 'title' => $of->name, 'title_normalized' => $of->name, 'text' => $text, 'text_status' => 'auto']);
            \App\Models\SourceEntry::create($more + ['citable_type' => Person::class, 'citable_id' => $of->id, 'source_id' => $source->id, 'source_volume_id' => $volume->id,
                'source_item_id' => $item->id, 'volume_number' => 1, 'page_start' => 10, 'role' => \App\Models\SourceEntry::ROLE_ENTRY,
                'origin' => \App\Models\SourceEntry::ORIGIN_MATCH, 'match_method' => 'name_death', 'confidence' => 90]);
        };
        $life($author, 'ولد بأصفهان سنة 1020 و توفي في النجف.');
        // A tie a reviewer rejected, and the life of a person outside the scope, are not read
        $life($author, 'توفي في بغداد.', ['status' => \App\Models\SourceEntry::STATUS_REJECTED]);
        $life($outside, 'ولد في شيراز.');

        $places = $this->places();
        $cities = collect($places['cities'])->keyBy('name');

        $this->assertSame(1, $cities['اصفهان']['origins']);
        $this->assertSame([1100 => 1], $cities['اصفهان']['origin_decades']);
        $this->assertSame(1, $cities['اصفهان']['births']);
        // A city known only from persons is on the map too, and is no city of copying
        $this->assertSame(['count' => 0, 'origins' => 1, 'deaths' => 1, 'death_decades' => [1100 => 1]],
            array_intersect_key($cities['نجف'], ['count' => 1, 'origins' => 1, 'deaths' => 1, 'death_decades' => 1]));
        // The scribe has no year of death: counted in the decade of the last copy, when the record has it
        $this->assertSame(1, $cities['تفرش']['origins']);
        $this->assertArrayNotHasKey('شیراز', $cities);
        $this->assertArrayNotHasKey('بغداد', $cities);
        $this->assertSame(2, $places['origins_mapped']);
        $this->assertSame(1, $places['births_mapped']);
        $this->assertSame(1, $places['deaths_mapped']);
        $this->assertSame(1, $places['lives_read']);
        $this->assertSame(1, $places['copy_cities']);
    }

    public function test_the_reviewers_word_on_a_place_of_a_life_comes_before_the_reading_of_the_rule()
    {
        $person = fn (string $name) => Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'insights-map-' . uniqid(),
            'death_year_hijri' => 1095, 'death_from' => 1095, 'death_to' => 1095]);
        [$first, $second] = [$person('محمد بن علی اصفهانی'), $person('حسن بن علی شیرازی')];
        foreach ([$first, $second] as $author) {
            $this->copy($this->work(['author_id' => $author->id]));
        }
        $period = fn (Person $of, string $kind, string $place, string $status) => \App\Models\PersonPeriod::create(
            ['person_id' => $of->id, 'kind' => $kind, 'place' => $place, 'status' => $status, 'origin' => 'machine']);
        // The nisba of the first is rejected as an origin; the second has an approved birthplace, a proposed place of
        // death and a proposed residence; a period of study is no layer of the map
        $period($first, 'origin', 'اصفهان', \App\Models\PersonPeriod::REJECTED);
        $period($second, 'birth', 'کاشان', \App\Models\PersonPeriod::APPROVED);
        $period($second, 'death', 'نجف', \App\Models\PersonPeriod::PROPOSED);
        $period($second, 'residence', 'دارالسلطنه اصفهان', \App\Models\PersonPeriod::PROPOSED);
        $period($second, 'study', 'قم', \App\Models\PersonPeriod::APPROVED);

        $places = $this->places();
        $cities = collect($places['cities'])->keyBy('name');

        $this->assertSame([0, 1, [1100 => 1]], [$cities['اصفهان']['origins'], $cities['اصفهان']['residences'], $cities['اصفهان']['residence_decades']]);
        $this->assertSame(1, $cities['شیراز']['origins']);
        $this->assertSame(1, $cities['کاشان']['births']);
        $this->assertSame(1, $cities['نجف']['deaths']);
        $this->assertArrayNotHasKey('قم', $cities);
        $this->assertSame([1, 1, 1, 1], [$places['origins_mapped'], $places['births_mapped'], $places['deaths_mapped'], $places['residences_mapped']]);
    }

    public function test_the_person_page_names_the_city_of_a_nisba_as_a_reading_of_the_name()
    {
        $with = Person::create(['name' => 'محمد بن علی هروی', 'normalized_name' => 'محمد بن علی هروی', 'slug' => 'insights-map-' . uniqid()]);
        $without = Person::create(['name' => 'محمد بن علی', 'normalized_name' => 'محمد بن علی', 'slug' => 'insights-map-' . uniqid()]);

        $this->get(route('people.show', $with))->assertOk()->assertSee('data-person-nisba', false)->assertSee('نسبت نام')->assertSee('هرات');
        $this->get(route('people.show', $without))->assertOk()->assertDontSee('data-person-nisba', false);
    }
}
