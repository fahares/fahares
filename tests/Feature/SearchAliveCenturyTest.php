<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Work;
use App\Support\DateEstimates\EstimateText;
use App\Support\SearchFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The authors' century filter and order by death with the century a person was last seen alive in, by the reader's
 * choice (step 14.198)
 */
class SearchAliveCenturyTest extends TestCase
{
    private Person $dead;

    private Person $alive;

    private Person $unknown;

    protected function setUp(): void
    {
        parent::setUp();
        $person = function (string $name, ?int $death) {
            $p = Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'sa-' . md5($name), 'death_year_hijri' => $death, 'is_author' => true, 'works_count' => 1]);
            DB::table('people')->where('id', $p->id)->update(['death_from' => $death, 'death_to' => $death]);

            return $p->fresh();
        };
        $work = function (Person $author, ?int $year) {
            $w = Work::create(['primary_title' => 'اثر ' . $author->name, 'clean_title' => 'اثر ' . $author->name, 'slug' => 'sa-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
            DB::table('works')->where('id', $w->id)->update(['composition_date_from' => $year, 'composition_date_to' => $year]);
            DB::table('work_people')->insert(['work_id' => $w->id, 'person_id' => $author->id, 'role' => 'author', 'position' => 0]);
        };
        $this->dead = $person('مؤلف درگذشته‌ی سده‌ی نهم آزمایشی', 880);
        $work($this->dead, 870);
        // no death year, a work of 859
        $this->alive = $person('مؤلف زنده‌ی سده‌ی نهم آزمایشی', null);
        $work($this->alive, 859);
        $this->unknown = $person('مؤلف بی‌قرینه‌ی آزمایشی', null);
        $work($this->unknown, null);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();
    }

    private function filters(array $query): SearchFilters
    {
        return SearchFilters::fromRequest(Request::create('/search', 'GET', $query), 'authors');
    }

    private function ids(array $query): array
    {
        return $this->filters($query)->apply(Person::query())->whereIn('people.id', [$this->dead->id, $this->alive->id, $this->unknown->id])
            ->orderBy('people.id')->pluck('people.id')->all();
    }

    public function test_the_century_takes_those_last_seen_alive_in_it_only_when_asked()
    {
        $this->assertSame(859, EstimateText::aliveYear($this->alive->id));
        $this->assertNull(EstimateText::aliveYear($this->dead->id));
        $this->assertNull(EstimateText::aliveYear($this->unknown->id));

        $this->assertSame([$this->dead->id], $this->ids(['century' => '9']));
        $this->assertSame([$this->dead->id, $this->alive->id], $this->ids(['century' => '9', 'estimates' => '1']));
        $this->assertSame([], $this->ids(['century' => '10', 'estimates' => '1']));

        $this->assertStringContainsString('(century_hijri = 9)', $this->filters(['century' => '9'])->engine());
        $this->assertStringContainsString('(century_hijri = 9 OR alive_century = 9)', $this->filters(['century' => '9', 'estimates' => '1'])->engine());
    }

    public function test_the_search_document_carries_the_century_and_the_year()
    {
        $document = fn (Person $p) => array_intersect_key((new Person)->makeAllSearchableUsing(Person::query())->whereKey($p->id)->sole()->toSearchableArray(),
            array_flip(['century_hijri', 'alive_century', 'death_sort_year']));

        $this->assertSame(['century_hijri' => null, 'alive_century' => 9, 'death_sort_year' => 859], $document($this->alive));
        $this->assertSame(['century_hijri' => 9, 'alive_century' => null, 'death_sort_year' => 880], $document($this->dead));
        $this->assertSame(['century_hijri' => null, 'alive_century' => null, 'death_sort_year' => null], $document($this->unknown));
        // one person alone reads the year by a query of its own
        $this->assertSame(859, $this->alive->fresh()->toSearchableArray()['death_sort_year']);
    }

    public function test_the_page_orders_by_the_year_and_marks_it()
    {
        $base = ['type' => 'authors', 'century' => '9', 'sort' => 'oldest'];

        $this->get(route('search', $base))->assertOk()->assertSee('مؤلف درگذشته‌ی سده‌ی نهم آزمایشی')->assertDontSee('مؤلف زنده‌ی سده‌ی نهم آزمایشی')
            ->assertSee('با سده‌ی فعالیت اشخاصِ بی‌تاریخ وفات');
        $this->get(route('search', $base + ['estimates' => '1']))->assertOk()
            ->assertSeeInOrder(['مؤلف زنده‌ی سده‌ی نهم آزمایشی', 'زنده در ۸۵۹ ق (تخمینی)', 'مؤلف درگذشته‌ی سده‌ی نهم آزمایشی'])
            ->assertDontSee('مؤلف بی‌قرینه‌ی آزمایشی');
    }
}
