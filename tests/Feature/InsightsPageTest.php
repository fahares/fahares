<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
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
        $this->manuscript($work, ['copy_date_hijri_year' => 1252, 'copy_place' => 'طهران دارالخلافه']);
        $this->manuscript($work, ['copy_date_hijri_year' => 1255]);
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
        $this->manuscript($work, ['copy_date_hijri_year' => 1100, 'copy_place' => 'هرات']);
        Insights::refresh();

        $this->get(route('insights'))->assertOk()
            ->assertSee('نسخه‌ها در گذر سده‌ها')
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'century' => 11])), false)
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'copy_city' => 'هرات'])), false)
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
}
