<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Support\CopyPlaces;
use App\Support\Insights;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InsightsPageTest extends TestCase
{
    use DatabaseTransactions;

    private function manuscript(Work $work, array $attributes = []): Manuscript
    {
        return Manuscript::create($attributes + [
            'work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
        ]);
    }

    private function work(string $title): Work
    {
        return Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'insights-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function author(string $lifeDates): Person
    {
        $name = 'مؤلف آزمایشی بینش ' . uniqid();

        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'insights-' . uniqid(), 'is_author' => true, 'life_dates_raw' => $lifeDates]);
    }

    private function century(array $data, string $chart, int $century): array
    {
        return collect($data[$chart]['centuries'])->firstWhere('century', $century);
    }

    public function test_a_copy_place_is_the_city_named_first_in_it()
    {
        $this->assertSame('تهران', CopyPlaces::cityOf('طهران'));
        $this->assertSame('تهران', CopyPlaces::cityOf('تهران (احتمالا)'));
        $this->assertSame('اصفهان', CopyPlaces::cityOf('اصفهان مدرسه جده'));
        $this->assertSame('اصفهان', CopyPlaces::cityOf('دارالسلطنه اصفهان'));
        $this->assertSame('قمصر', CopyPlaces::cityOf('قمصر کاشان'));
        $this->assertSame('کرمانشاه', CopyPlaces::cityOf('کرمانشاهان'));
        $this->assertSame('کرمان', CopyPlaces::cityOf('لنگر کرمان'));
        $this->assertSame('مکه', CopyPlaces::cityOf('مكه مكرمه'));
        $this->assertSame('نجف', CopyPlaces::cityOf('نجف اشرف'));
        $this->assertSame('استانبول', CopyPlaces::cityOf('قسطنطنیه'));
        $this->assertSame('ماردین', CopyPlaces::cityOf('ماردین در شمال عراق'));
        $this->assertNull(CopyPlaces::cityOf('ایران'));
        $this->assertNull(CopyPlaces::cityOf('هندوستان'));
        $this->assertNull(CopyPlaces::cityOf(''));
        $this->assertNull(CopyPlaces::cityOf(null));
    }

    public function test_figures_count_dated_and_placed_copies()
    {
        $before = Insights::build();
        $work = $this->work('اثر آزمایشی بینش');
        $this->manuscript($work, ['copy_date_raw' => '1252ق', 'copy_place' => 'طهران دارالخلافه']);
        $this->manuscript($work, ['copy_date_raw' => '1255ق']);
        $this->manuscript($work, ['copy_place' => 'ایران']);

        $after = Insights::build();
        $century = fn ($data) => collect($data['timeline']['centuries'])->firstWhere('century', 13);
        $tehran = fn ($data) => collect($data['places']['cities'])->firstWhere('name', 'تهران');

        $this->assertSame($century($before)['count'] + 2, $century($after)['count']);
        $this->assertSame($century($before)['decades'][5] + 2, $century($after)['decades'][5]);
        $this->assertSame(($tehran($before)['count'] ?? 0) + 1, $tehran($after)['count']);
        $this->assertSame(($tehran($before)['centuries'][13] ?? 0) + 1, $tehran($after)['centuries'][13]);
        $this->assertSame($before['places']['with_place'] + 2, $after['places']['with_place']);
        $this->assertSame($before['places']['mapped'] + 1, $after['places']['mapped']);
    }

    public function test_generic_author_less_titles_stay_out_of_the_top_works()
    {
        $generic = $this->work('اشعار');
        $generic->forceFill(['manuscripts_count' => 999999])->save();
        $named = $this->work('اثر بسیار پرنسخه‌ی آزمایشی');
        $named->forceFill(['manuscripts_count' => 999998])->save();

        $top = Insights::build()['top_works'];

        $this->assertSame('اثر بسیار پرنسخه‌ی آزمایشی', $top['items'][0]['title']);
        $this->assertContains('اشعار', array_column($top['skipped'], 'title'));
        $this->assertNotContains('اشعار', array_column($top['items'], 'title'));
    }

    public function test_the_page_shows_the_figures_with_links_to_search()
    {
        $work = $this->work('اثر آزمایشی صفحه‌ی بینش');
        $this->manuscript($work, ['copy_date_raw' => '1100ق', 'copy_place' => 'هرات']);
        Insights::refresh();

        $this->get(route('insights'))->assertOk()
            ->assertSee('نسخه‌ها در گذر سده‌ها')
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'century' => 11])), false)
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'copy_city' => 'هرات'])), false)
            ->assertSee('پدیدآورندگان و آثار در گذر سده‌ها')
            ->assertSee('فاصله‌ی کتابت تا وفات مؤلف')
            ->assertSee(e(route('search', ['type' => 'people', 'role' => 'author', 'century' => 11])), false)
            ->assertSee('کانون‌های تاریخی استنساخ')
            ->assertSee('سهم خطوط')
            ->assertSee('سهم زبان‌ها');
    }

    public function test_search_filters_copies_by_city_of_copying()
    {
        $library = Library::create(['name' => 'کتابخانه‌ی آزمایشی بینش' . uniqid(), 'city' => 'شهر آزمایشی', 'slug' => 'lib-insights-' . uniqid()]);
        $inTehran = $this->work('اثر کتابت‌شده در طهران');
        $inShiraz = $this->work('اثر کتابت‌شده در شیراز');
        $this->manuscript($inTehran, ['library_id' => $library->id, 'copy_place' => 'طهران دارالخلافه']);
        $this->manuscript($inShiraz, ['library_id' => $library->id, 'copy_place' => 'شیراز']);
        CopyPlaces::variantMap(fresh: true);

        $this->get(route('search', ['type' => 'manuscripts', 'library_id' => $library->id, 'copy_city' => 'تهران']))
            ->assertOk()
            ->assertSee('محل کتابت')
            ->assertSee('اثر کتابت‌شده در طهران')
            ->assertDontSee('اثر کتابت‌شده در شیراز');

        // A name that is not a known city is ignored, not an empty result
        $this->get(route('search', ['type' => 'manuscripts', 'library_id' => $library->id, 'copy_city' => 'ناکجاآباد']))
            ->assertOk()
            ->assertSee('اثر کتابت‌شده در طهران')
            ->assertSee('اثر کتابت‌شده در شیراز');
    }

    public function test_the_copy_timeline_layers_years_centuries_and_doubtful_dates()
    {
        $before = Insights::build()['timeline'];
        $work = $this->work('اثر آزمایشی بازه‌ی کتابت');
        $this->manuscript($work, ['copy_date_raw' => '1252ق']);
        $this->manuscript($work, ['copy_date_raw' => '1237ق؟']);
        $this->manuscript($work, ['copy_date_raw' => 'قرن 13']);
        $this->manuscript($work, ['copy_date_raw' => 'محتملاً اواخر قرن 13']);
        // Two centuries: each copy is half in each, so the two of them make one in each
        $this->manuscript($work, ['copy_date_raw' => 'قرن 12 و 13']);
        $this->manuscript($work, ['copy_date_raw' => 'قرن 12 و 13']);
        $this->manuscript($work, ['copy_date_raw' => 'پیش از 1100']);

        $after = Insights::build()['timeline'];
        $was = collect($before['centuries'])->keyBy('century');
        $now = collect($after['centuries'])->keyBy('century');

        $this->assertSame($was[13]['exact'] + 1, $now[13]['exact']);
        $this->assertSame($was[13]['exact_doubtful'] + 1, $now[13]['exact_doubtful']);
        $this->assertSame($was[13]['range'] + 2, $now[13]['range']);
        $this->assertSame($was[13]['range_doubtful'] + 1, $now[13]['range_doubtful']);
        $this->assertSame($was[12]['range'] + 1, $now[12]['range']);
        $this->assertSame($was[13]['decades'][5] + 1, $now[13]['decades'][5]);
        $this->assertSame($was[13]['decades_doubtful'][3] + 1, $now[13]['decades_doubtful'][3]);
        $this->assertSame($before['open'] + 1, $after['open']);
        $this->assertSame($before['counted'] + 6, $after['counted']);
        $this->assertSame($before['doubtful'] + 2, $after['doubtful']);
    }

    public function test_authors_are_counted_by_their_death_range_and_works_by_composition()
    {
        $before = Insights::build();
        $doubtful = $this->author('1166 - 1241؟ ق');
        $byCentury = $this->author('ق13');
        $this->author('1100 - 1180 ق'); // no work, so not an author on the chart
        $this->work('اثر آزمایشی مؤلف مردد')->forceFill(['author_id' => $doubtful->id, 'composition_date_raw' => '1220ق'])->save();
        $this->work('اثر آزمایشی مؤلف سده‌ای')->forceFill(['author_id' => $byCentury->id, 'composition_date_raw' => 'اوایل قرن 13'])->save();
        $this->work('اثر آزمایشی دیگر همان مؤلف')->forceFill(['author_id' => $byCentury->id])->save();

        $after = Insights::build();

        $this->assertSame($this->century($before, 'authors', 13)['exact_doubtful'] + 1, $this->century($after, 'authors', 13)['exact_doubtful']);
        $this->assertSame($this->century($before, 'authors', 13)['range'] + 1, $this->century($after, 'authors', 13)['range']);
        $this->assertSame($this->century($before, 'authors', 12)['count'], $this->century($after, 'authors', 12)['count']);
        $this->assertSame($before['authors']['counted'] + 2, $after['authors']['counted']);
        $this->assertSame($this->century($before, 'compositions', 13)['exact'] + 1, $this->century($after, 'compositions', 13)['exact']);
        $this->assertSame($this->century($before, 'compositions', 13)['range'] + 1, $this->century($after, 'compositions', 13)['range']);
    }

    public function test_copies_are_banded_by_the_years_after_their_authors_death()
    {
        $band = fn (array $data, ?int $upto) => collect($data['copy_gap']['bands'])->firstWhere('upto', $upto);
        $before = Insights::build();
        $author = $this->author('1180 - 1250 ق');
        $work = $this->work('اثر آزمایشی فاصله‌ی نسخه');
        $work->forceFill(['author_id' => $author->id])->save();
        $this->manuscript($work, ['copy_date_raw' => '1230ق']); // in his lifetime
        $this->manuscript($work, ['copy_date_raw' => '1236ق؟']); // the same, doubtful
        $this->manuscript($work, ['copy_date_raw' => '1400ق']); // 150 years after
        $this->manuscript($work, ['copy_date_raw' => '1100ق']); // 150 years before: a date or an attribution at fault
        $this->manuscript($work, ['copy_date_raw' => 'قرن 15']); // 151 to 250 years after: across two bands
        $this->manuscript($work, ['copy_date_raw' => 'قرن 9']); // 351 to 450 years before: the first band whole

        $after = Insights::build();

        $this->assertSame($band($before, 0)['certain'] + 1, $band($after, 0)['certain']);
        $this->assertSame($band($before, 0)['doubtful'] + 1, $band($after, 0)['doubtful']);
        $this->assertSame($band($before, 200)['count'] + 1, $band($after, 200)['count']);
        $this->assertSame($band($before, -80)['count'] + 2, $band($after, -80)['count']);
        $this->assertSame($before['copy_gap']['paired'] + 6, $after['copy_gap']['paired']);
        $this->assertSame($before['copy_gap']['unplaced'] + 1, $after['copy_gap']['unplaced']);
    }
}
