<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Subject;
use App\Models\Work;
use App\Support\CopyPlaces;
use App\Support\Insights;
use App\Support\InsightsScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The insights page narrowed to an author, a library, a subject and so on (backlog item 107) */
class InsightsScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function library(string $city = 'تهران'): Library
    {
        return Library::create(['name' => 'کتابخانه‌ی آزمایشی دامنه ' . uniqid(), 'city' => $city, 'slug' => 'lib-scope-' . uniqid()]);
    }

    private function work(array $attributes = []): Work
    {
        $title = $attributes['primary_title'] ?? 'اثر آزمایشی دامنه ' . uniqid();
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'scope-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $attributes && $work->forceFill($attributes)->save();

        return $work;
    }

    private function person(string $role = 'author'): Person
    {
        $name = 'شخص آزمایشی دامنه ' . uniqid();

        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'scope-' . uniqid(), "is_{$role}" => true]);
    }

    private function copy(Work $work, array $attributes = []): Manuscript
    {
        return Manuscript::create($attributes + ['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
    }

    /** Copies enough for every chart of a small scope to be drawn */
    private function copies(Work $work, int $n, array $attributes = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->copy($work, $attributes);
        }
    }

    public function test_the_scope_reads_its_filters_from_the_query_and_drops_bad_ones()
    {
        $author = $this->person();
        $scope = InsightsScope::fromArray(['century' => '۸-۵', 'author' => (string) $author->id, 'script' => '999999999', 'copy_city' => 'ناکجا', 'library' => 'abc']);

        $this->assertSame(['author' => $author->id, 'century' => [5, 8]], $scope->values);
        $this->assertSame(['author' => $author->id, 'century' => '5-8'], $scope->query());
        $this->assertSame(InsightsScope::fromArray(['author' => $author->id, 'century' => '5-8'])->key(), $scope->key());
        $this->assertSame(['century' => '5-8'], $scope->without('author'));
        $this->assertSame(['آثار ' . $author->name, 'سده‌های ۵ تا ۸'], array_column($scope->labels(), 1));
        $this->assertSame(['author'], $scope->unsearchable());
        $this->assertTrue(InsightsScope::fromArray(['century' => '16'])->isEmpty());
        $this->assertSame([7, 7], InsightsScope::fromArray(['century' => '7'])->get('century'));
    }

    public function test_a_scope_counts_only_its_own_copies_and_works()
    {
        $library = $this->library();
        $other = $this->library();
        $author = $this->person();
        $work = $this->work(['author_id' => $author->id]);
        $this->copy($work, ['library_id' => $library->id, 'copy_date_raw' => '1252ق']);
        $this->copy($work, ['library_id' => $library->id, 'copy_date_raw' => 'قرن 11']);
        $this->copy($this->work(), ['library_id' => $other->id, 'copy_date_raw' => '1253ق']);

        $data = Insights::build(InsightsScope::fromArray(['library' => $library->id]));
        $century = collect($data['timeline']['centuries'])->keyBy('century');

        $this->assertSame(['manuscripts' => 2, 'works' => 1], $data['totals']);
        $this->assertSame(1, $century[13]['exact']);
        $this->assertSame(1, $century[11]['range']);
        $this->assertSame(1, $data['authors']['total']);
        $this->assertSame([2], array_column($data['top_works']['items'], 'count'));

        // The work filters keep the author's works, and the copies of them; the century keeps copies within it
        $byAuthor = Insights::build(InsightsScope::fromArray(['author' => $author->id, 'century' => '13']));
        $this->assertSame(['manuscripts' => 1, 'works' => 1], $byAuthor['totals']);
    }

    public function test_places_of_composition_are_a_layer_of_the_map_with_their_names_merged()
    {
        $author = $this->person();
        $this->work(['author_id' => $author->id, 'composition_place' => 'مشهد رضوی', 'composition_date_raw' => '1100ق']);
        $this->work(['author_id' => $author->id, 'composition_place' => 'مشهد مقدس']);
        $this->work(['author_id' => $author->id, 'composition_place' => 'ایران']);
        $copied = $this->work(['author_id' => $author->id]);
        $this->copy($copied, ['copy_place' => 'مشهد']);

        $places = Insights::build(InsightsScope::fromArray(['author' => $author->id]))['places'];
        $mashhad = collect($places['cities'])->firstWhere('name', 'مشهد');

        $this->assertSame(1, $mashhad['count']);
        $this->assertSame(2, $mashhad['works']);
        $this->assertSame([11 => 1], $mashhad['work_centuries']);
        $this->assertSame(3, $places['composition']['with_place']);
        $this->assertSame(2, $places['composition']['mapped']);
        $this->assertSame(50.0, $places['composition']['coverage']);
    }

    public function test_copies_are_followed_from_the_city_of_copying_to_the_city_of_their_library()
    {
        $author = $this->person();
        $work = $this->work(['author_id' => $author->id]);
        $tehran = $this->library('تهران');
        $mashhad = $this->library('مشهد');
        foreach ([[$tehran, 'هرات'], [$tehran, 'هرات'], [$mashhad, 'هری'], [$mashhad, 'مشهد'], [$mashhad, 'ایران']] as [$library, $place]) {
            $this->copy($work, ['library_id' => $library->id, 'city' => $library->city, 'copy_place' => $place]);
        }

        $movement = Insights::build(InsightsScope::fromArray(['author' => $author->id]))['movement'];
        $herat = collect($movement['origins'])->firstWhere('name', 'هرات');

        $this->assertSame(4, $movement['mapped']);
        $this->assertSame(1, $movement['stayed']);
        $this->assertSame(3, $herat['total']);
        $this->assertSame(['تهران' => 2, 'مشهد' => 1], array_column($herat['items'], 'count', 'name'));
        $this->assertEquals(['هرات' => 1, 'مشهد' => 1], array_column(collect($movement['destinations'])->firstWhere('name', 'مشهد')['items'], 'count', 'name'));
    }

    public function test_subjects_and_features_are_shared_out_per_century()
    {
        $root = Subject::create(['name' => 'موضوع آزمایشی اصلی ' . uniqid(), 'slug' => 'subject-' . uniqid()]);
        $child = Subject::create(['name' => 'زیرموضوع آزمایشی ' . uniqid(), 'slug' => 'subject-' . uniqid(), 'parent_id' => $root->id]);
        $other = Subject::create(['name' => 'موضوع آزمایشی دیگر ' . uniqid(), 'slug' => 'subject-' . uniqid()]);
        $author = $this->person();
        $both = $this->work(['author_id' => $author->id]);
        $both->subjects()->attach([$root->id, $child->id]);
        $plain = $this->work(['author_id' => $author->id]);
        $plain->subjects()->attach($other->id);
        $this->copy($both, ['copy_date_raw' => '1250ق', 'is_illuminated' => true]);
        $this->copy($both, ['copy_date_raw' => 'قرن 13', 'is_illuminated' => true, 'is_corrected' => true]);
        $this->copy($plain, ['copy_date_raw' => '1210ق']);
        $this->copy($plain, ['copy_date_raw' => '1110ق']);
        $this->copy($plain, ['copy_date_raw' => 'قرن 12 و 13']); // across two centuries: in no grid

        $data = Insights::build(InsightsScope::fromArray(['author' => $author->id]));
        $subjects = collect($data['subjects']['rows'])->keyBy('key');
        $features = collect($data['features']['rows'])->keyBy('key');

        // The root and its child are one main subject, and a copy counts once in it
        $this->assertSame(['count' => 2, 'share' => 66.7], $subjects[$root->id]['cells'][13]);
        $this->assertSame(['count' => 1, 'share' => 33.3], $subjects[$other->id]['cells'][13]);
        $this->assertSame(['count' => 1, 'share' => 100.0], $subjects[$other->id]['cells'][12]);
        $this->assertSame(4, $data['subjects']['counted']);
        $this->assertSame(['count' => 2, 'share' => 66.7], $features['is_illuminated']['cells'][13]);
        $this->assertSame(['count' => 1, 'share' => 33.3], $features['is_corrected']['cells'][13]);
        $this->assertSame(['century' => 12, 'count' => 1], collect($data['features']['centuries'])->firstWhere('century', 12));

        // Under a subject of its own, the grid is of its subjects
        $bySubject = Insights::build(InsightsScope::fromArray(['subject' => $root->id]))['subjects'];
        $this->assertTrue($bySubject['children']);
        $this->assertEqualsCanonicalizing([$root->id, $child->id], array_column($bySubject['rows'], 'key'));
    }

    public function test_scribes_libraries_sizes_languages_and_catalogues_of_a_scope()
    {
        $author = $this->person();
        $scribe = $this->person('scribe');
        $old = $this->library('هرات');
        $new = $this->library('تهران');
        $volume = \App\Models\CatalogVolume::create(['title' => 'فهرست آزمایشی دامنه ' . uniqid(), 'volume_number' => 2, 'library_id' => $old->id]);
        $persian = \App\Models\Language::firstOrCreate(['name' => 'فارسی'], ['slug' => 'persian-' . uniqid()]);
        $work = $this->work(['author_id' => $author->id]);
        $work->languages()->attach($persian->id);
        $this->copy($work, ['scribe_id' => $scribe->id, 'library_id' => $old->id, 'copy_date_raw' => '1050ق', 'copy_place' => 'هرات', 'folios' => 30, 'lines' => 15, 'catalog_volume_id' => $volume->id]);
        $this->copy($work, ['scribe_id' => $scribe->id, 'library_id' => $old->id, 'copy_date_raw' => '1080ق', 'copy_place' => 'هرات', 'folios' => 250, 'lines' => 21, 'catalog_volume_id' => $volume->id]);
        $this->copy($work, ['scribe_id' => $scribe->id, 'library_id' => $new->id, 'copy_date_raw' => '1250ق', 'copy_place' => 'تهران', 'folios' => 10]);

        $data = Insights::build(InsightsScope::fromArray(['author' => $author->id]));

        $this->assertSame([['id' => $scribe->id, 'key' => $scribe->getRouteKey(), 'name' => $scribe->name, 'count' => 3, 'from' => 1050, 'to' => 1250, 'city' => 'هرات']], $data['scribes']['items']);
        $this->assertSame([$old->id, $new->id], array_column($data['libraries']['largest'], 'id'));
        $this->assertSame(1065, $data['libraries']['largest'][0]['mean']);
        $this->assertSame([], $data['libraries']['oldest']); // fewer dated copies than oldest_min_dated
        $this->assertSame([1, 1, 0, 0, 1, 0], array_column($data['sizes']['bands'], 'count'));
        $this->assertSame(30, $data['sizes']['median']);
        $eleventh = collect($data['sizes']['centuries'])->firstWhere('century', 11);
        $this->assertEquals(['century' => 11, 'folios' => 140, 'with_folios' => 2, 'lines' => 18.0, 'with_lines' => 2], $eleventh);
        $this->assertSame(['count' => 2, 'share' => 100.0], $data['language_scripts']['languages']['rows'][0]['cells'][11]);
        $this->assertSame($persian->id, $data['language_scripts']['languages']['rows'][0]['key']);
        $this->assertSame([['id' => $volume->id, 'title' => $volume->title, 'volume' => 2, 'library' => $old->name, 'count' => 2]], $data['sources']['items']);
        $this->assertSame(1, $data['sources']['unknown']);
    }

    public function test_the_scoped_page_names_its_scope_and_carries_it_into_search_links()
    {
        $library = $this->library('شیراز');
        $work = $this->work(['primary_title' => 'اثر آزمایشی صفحه‌ی دامنه']);
        $this->copies($work, 12, ['library_id' => $library->id, 'city' => 'شیراز', 'copy_date_raw' => '1100ق', 'copy_place' => 'هرات', 'is_illuminated' => true]);

        $this->get(route('insights', ['library' => $library->id]))->assertOk()
            ->assertSee('آمار نسخه‌های کتابخانه‌ی ' . $library->name . ' (شیراز)')
            ->assertSee('۱۲ نسخه از ۱ اثر')
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('بازگشت به آمار کل')
            ->assertSee('نمونه‌ی کوچک')
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'century' => 11, 'library_id' => $library->id])), false)
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'copy_city' => 'هرات', 'library_id' => $library->id])), false)
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'flags' => ['is_illuminated'], 'century' => 11, 'library_id' => $library->id])), false)
            ->assertSee('از جای کتابت تا کتابخانه‌ی امروز')
            ->assertSee('ویژگی‌های نسخه‌ها در گذر سده‌ها')
            // The scope has one work: no table of its most-copied works
            ->assertDontSee('آثار پرنسخه در این دامنه');
    }

    public function test_charts_of_a_scope_with_too_little_data_are_not_drawn()
    {
        $author = $this->person();
        $this->copy($this->work(['author_id' => $author->id]), ['copy_date_raw' => '1100ق']);

        $this->get(route('insights', ['author' => $author->id]))->assertOk()
            ->assertSee('آمار نسخه‌های آثار ' . $author->name)
            ->assertSee('در این دامنه تنها ۱ نسخه‌ی تاریخ‌دار هست که برای این نمودار بسنده نیست.')
            ->assertSee('پیوند نمودارها به جست‌وجوی نسخه‌ها فیلتر مؤلف را با خود نمی‌برد');
    }

    public function test_a_scope_is_cached_by_its_filters_and_unknown_filters_show_the_whole_page()
    {
        $library = $this->library();
        $this->copy($this->work(), ['library_id' => $library->id]);
        $scope = InsightsScope::fromArray(['library' => $library->id]);

        $this->assertFalse(Insights::isCached($scope));
        $this->assertSame(1, Insights::get($scope)['totals']['manuscripts']);
        $this->assertTrue(Insights::isCached($scope));
        $this->copy($this->work(), ['library_id' => $library->id]);
        $this->assertSame(1, Insights::get(InsightsScope::fromArray(['library' => (string) $library->id]))['totals']['manuscripts']);

        Cache::forever('insights.v4', Insights::build());
        $this->get(route('insights', ['library' => 'x', 'subject' => '0']))->assertOk()
            ->assertSee('نسخه‌ها در گذر سده‌ها')
            ->assertDontSee('noindex');
    }

    public function test_the_filter_boxes_look_up_their_choices()
    {
        $library = $this->library('یزد');
        $this->copy($this->work(), ['library_id' => $library->id, 'city' => 'یزد']);
        Cache::forget('insights.lookup.cities');

        $this->getJson(route('insights.lookup', ['field' => 'library', 'q' => $library->name]))->assertOk()
            ->assertExactJson([['v' => $library->id, 'label' => $library->name, 'note' => 'یزد']]);
        $this->getJson(route('insights.lookup', ['field' => 'copy_city', 'q' => 'طهران']))->assertOk()
            ->assertJsonPath('0.v', 'تهران');
        $this->getJson(route('insights.lookup', ['field' => 'city', 'q' => 'یز']))->assertOk()
            ->assertJsonPath('0.v', 'یزد');
        $this->getJson(route('insights.lookup', ['field' => 'nothing', 'q' => 'x']))->assertOk()->assertExactJson([]);
    }

    public function test_a_copy_place_variant_of_a_city_counts_as_the_city()
    {
        $this->assertSame('مشهد', CopyPlaces::cityOf('مشهد رضوی'));
        $this->assertSame('مشهد', CopyPlaces::cityOf('مشهد مقدس'));
    }
}
