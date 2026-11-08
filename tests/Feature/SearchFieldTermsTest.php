<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\SearchLog;
use App\Models\Work;
use App\Support\FieldTerms;
use App\Support\SearchFilters;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Words searched each in its own field, all together (backlog item 83, step 14.100) */
class SearchFieldTermsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FieldTerms::flush();
    }

    private function manuscript(string $shelfmark, array $attrs): Manuscript
    {
        $work = Work::create(['primary_title' => 'اثر چندفیلدی', 'clean_title' => 'اثر چندفیلدی', 'slug' => 'fields-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create($attrs + ['work_id' => $work->id, 'shelfmark' => $shelfmark, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => 'نسخه']);
    }

    public function test_terms_are_cleaned_to_the_fields_of_the_tab(): void
    {
        $request = Request::create('/search', 'GET', ['f' => ['scribe' => ' محمد  باقر ', 'place' => '', 'bogus' => 'x', 'author' => ['array']]]);

        $this->assertSame(['scribe' => 'محمد باقر'], SearchFilters::fromRequest($request, 'manuscripts')->get('fields'));
        $this->assertNull(SearchFilters::fromRequest($request, 'works')->get('fields'), 'a field of another tab is dropped');
        $this->assertNull(SearchFilters::fromRequest(Request::create('/search', 'GET', ['f' => 'محمد']), 'manuscripts')->get('fields'));
    }

    public function test_every_field_must_match_its_own_words(): void
    {
        $both = $this->manuscript('FT-1', ['scribe_name' => 'محمد باقر', 'copy_place' => 'اصفهان']);
        $this->manuscript('FT-2', ['scribe_name' => 'محمد باقر', 'copy_place' => 'تبریز']);
        // The words in the wrong fields: the scribe is not «محمد»
        $this->manuscript('FT-3', ['scribe_name' => 'علی اصفهانی', 'copy_place' => 'اصفهان', 'commissioned_by' => 'محمد']);

        $filters = SearchFilters::fromRequest(Request::create('/search', 'GET', ['f' => ['scribe' => 'محمد', 'place' => 'اصفهان']]), 'manuscripts');
        $this->assertSame([$both->id], $filters->fieldMatches()['ids']);
        $this->assertSame("(id IN [{$both->id}])", $filters->engine());

        $this->get(route('search', ['type' => 'manuscripts', 'f' => ['scribe' => 'محمد', 'place' => 'اصفهان']]))->assertOk()
            ->assertSee('FT-1')->assertDontSee('FT-2')->assertDontSee('FT-3')
            ->assertSee('کاتب: محمد')->assertSee('محل کتابت: اصفهان')
            ->assertSee('name="f[scribe]" value="محمد"', false);
    }

    public function test_no_match_finds_nothing_and_a_chip_removes_one_field(): void
    {
        $this->manuscript('FT-4', ['scribe_name' => 'حسن', 'copy_place' => 'قم']);

        $response = $this->get(route('search', ['type' => 'manuscripts', 'f' => ['scribe' => 'ناموجود', 'place' => 'قم']]))->assertOk()->assertDontSee('FT-4');
        $chips = SearchFilters::fromRequest(Request::create('/search', 'GET', ['type' => 'manuscripts', 'f' => ['scribe' => 'ناموجود', 'place' => 'قم']]), 'manuscripts')->active();
        $this->assertSame([['کاتب: ناموجود', ['f' => ['place' => 'قم']]], ['محل کتابت: قم', ['f' => ['scribe' => 'ناموجود']]]], $chips);
        $response->assertSee('جست‌وجو در چند فیلد با هم');
    }

    public function test_fields_alone_are_logged_as_a_search(): void
    {
        $this->manuscript('FT-5', ['scribe_name' => 'جعفر', 'copy_place' => 'شیراز']);
        $this->manuscript('FT-6', ['scribe_name' => 'رضا', 'copy_place' => 'شیراز']);

        $this->get(route('search', ['type' => 'manuscripts', 'f' => ['scribe' => 'جعفر', 'place' => 'شیراز']]))->assertOk()
            ->assertSee('FT-5')->assertDontSee('FT-6');

        $this->assertSame(['fields' => ['scribe' => 'جعفر', 'place' => 'شیراز']], SearchLog::latest('id')->first()->filters);
    }

    public function test_the_engine_searches_from_the_narrowest_field_within_the_ids_before(): void
    {
        config(['scout.driver' => 'meilisearch']);
        $result = fn (array $ids, int $total) => new \Meilisearch\Search\SearchResult(['hits' => array_map(fn ($id) => ['id' => $id], $ids),
            'estimatedTotalHits' => $total, 'offset' => 0, 'limit' => FieldTerms::CAP, 'processingTimeMs' => 1, 'query' => '']);
        $index = \Mockery::mock(\Meilisearch\Endpoints\Indexes::class);
        // «محمد» as scribe matches 20 000, «اصفهان» as place 300: the place first, then the scribe within its ids
        $index->shouldReceive('search')->once()->ordered()->with('اصفهان', \Mockery::on(fn ($o) => $o['attributesToSearchOn'] === ['copy_place'] && ! isset($o['filter'])))
            ->andReturn($result([3, 5, 8], 300));
        $index->shouldReceive('search')->once()->ordered()->with('محمد', \Mockery::on(fn ($o) => $o['attributesToSearchOn'] === ['scribe_name'] && $o['filter'] === 'id IN [3, 5, 8]'))
            ->andReturn($result([5], 1));
        $client = \Mockery::mock(\Meilisearch\Client::class);
        $client->shouldReceive('multiSearch')->once()->andReturn(['results' => [['estimatedTotalHits' => 20000], ['estimatedTotalHits' => 300]]]);
        $client->shouldReceive('index')->with('manuscripts_index')->andReturn($index);
        $this->app->instance(\Meilisearch\Client::class, $client);

        $this->assertSame(['ids' => [5], 'broad' => [], 'failed' => false], FieldTerms::resolve('manuscripts', ['scribe' => 'محمد', 'place' => 'اصفهان']));

        // The narrowest one is cut at CAP, and the page says so
        FieldTerms::flush();
        $client->shouldReceive('multiSearch')->once()->andReturn(['results' => [['estimatedTotalHits' => 20000]]]);
        $index->shouldReceive('search')->once()->andReturn($result([1, 2], 20000));
        $filters = SearchFilters::fromRequest(Request::create('/search', 'GET', ['f' => ['scribe' => 'محمد']]), 'manuscripts');
        $this->assertSame(['کاتب'], $filters->broadFields());
    }

    public function test_an_engine_that_fails_says_so(): void
    {
        config(['scout.driver' => 'meilisearch']);
        $this->app->instance(\Meilisearch\Client::class, new \Meilisearch\Client('http://127.0.0.1:9'));

        $this->get(route('search', ['type' => 'manuscripts', 'f' => ['scribe' => 'محمد']]))->assertOk()->assertSee('جست‌وجو موقتاً در دسترس نیست');
    }

    public function test_people_and_sources_have_no_field_panel(): void
    {
        $this->get(route('search', ['type' => 'people', 'f' => ['scribe' => 'محمد']]))->assertOk()->assertDontSee('name="f[', false);
    }
}
