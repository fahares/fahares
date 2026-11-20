<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Person;
use App\Support\SearchFilters;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The centuries of a life (step 14.247): century_hijri stays the one century the lists order by, and century_from
 * is the first century of a life that lies in more than one; the pages name both and the century filter finds the
 * person under each.
 */
class LifeCenturiesTest extends TestCase
{
    use DatabaseTransactions;

    private function person(string $name, array $columns = []): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'lc-' . uniqid(), 'is_author' => true] + $columns)->fresh();
    }

    public function test_the_first_century_follows_the_birth_year_and_a_set_one_stands_without_it()
    {
        $two = $this->person('دوسده‌ای آزمایشی', ['birth_year_hijri' => 970, 'death_year_hijri' => 1050]);
        $this->assertSame([10, 11, [10, 11]], [$two->century_from, $two->century_hijri, $two->lifeCenturies()]);
        $this->assertSame('سده‌های ۱۰ و ۱۱ ق', $two->centuries_text);
        // the years are shown where both are known
        $this->assertSame('۹۷۰–۱۰۵۰ ق', $two->life_span);
        $this->assertSame(1050, $two->eraYear(), 'the order stays by the death');

        $one = $this->person('یک‌سده‌ای آزمایشی', ['birth_year_hijri' => 1010, 'death_year_hijri' => 1080]);
        $this->assertSame([null, 11, [11], 'سدۀ ۱۱ ق'], [$one->century_from, $one->century_hijri, $one->lifeCenturies(), $one->centuries_text]);

        // a birth year that moves into the death's century empties it; one that moves out fills it
        $two->update(['birth_year_hijri' => 1001]);
        $this->assertNull($two->fresh()->century_from);
        $one->update(['birth_year_hijri' => 999]);
        $this->assertSame(10, $one->fresh()->century_from);
        // ...as does a death year that moves the person's century
        $one->update(['death_year_hijri' => 1000]);
        $this->assertSame([null, 10], [$one->fresh()->century_from, $one->fresh()->century_hijri]);

        // no birth year: the century a reviewer set stands, through later saves, while it is before the person's own
        $set = $this->person('مجنون آزمایشی', ['life_dates_raw' => '- 940 ؟ ق', 'death_year_hijri' => 940]);
        $this->assertNull($set->century_from);
        $set->update(['century_from' => 9]);
        $set->update(['bio_notes' => 'خوشنویس', 'death_year_gregorian' => 1534]);
        $this->assertSame([9, 10, 'سده‌های ۹ و ۱۰ ق'], [$set->fresh()->century_from, $set->fresh()->century_hijri, $set->fresh()->centuries_text]);
        $set->update(['death_year_hijri' => 899]);
        $this->assertNull($set->fresh()->century_from, 'not before the century any more');

        // only a century: «سده‌های ۹ و ۱۰ ق» in place of «سدۀ ۱۰ ق», three as a span, and none without a century
        $century = $this->person('سده‌دار آزمایشی', ['century_hijri' => 10]);
        $this->assertSame('سدۀ ۱۰ ق', $century->life_span);
        $century->update(['century_from' => 9]);
        $this->assertSame('سده‌های ۹ و ۱۰ ق', $century->fresh()->life_span);
        $century->update(['century_from' => 8]);
        $this->assertSame('سده‌های ۸ تا ۱۰ ق', $century->fresh()->life_span);
        $century->update(['century_from' => 10]);
        $this->assertNull($century->fresh()->century_from, 'the same century is one century');
        $this->assertSame([], $this->person('بی‌سدۀ آزمایشی', ['century_from' => 9])->lifeCenturies());

        // a birth year after the century, or far before it, is an error of one of the two
        $this->assertNull(Person::centuryFrom(1105, 10, null));
        $this->assertNull(Person::centuryFrom(650, 10, null));
        $this->assertSame(8, Person::centuryFrom(799, 10, null));
    }

    public function test_the_person_page_and_the_lists_name_both_centuries()
    {
        $two = $this->person('دوسده‌ای صفحه آزمایشی', ['birth_year_hijri' => 970, 'death_year_hijri' => 1050]);
        $this->get(route('people.show', $two))->assertOk()->assertSee('سده‌های ۱۰ و ۱۱ ق')->assertDontSee('سدۀ ۱۱ ق');

        $set = $this->person('نه و ده صفحه آزمایشی', ['century_hijri' => 10, 'century_from' => 9]);
        $this->get(route('people.show', $set))->assertOk()->assertSee('سده‌های ۹ و ۱۰ ق')->assertDontSee('سدۀ ۱۰ ق');
    }

    public function test_the_century_filter_and_the_index_find_the_person_under_each_century()
    {
        $two = $this->person('دوسده‌ای فیلتر آزمایشی', ['birth_year_hijri' => 970, 'death_year_hijri' => 1050]);
        $set = $this->person('نه و ده فیلتر آزمایشی', ['century_hijri' => 10, 'century_from' => 9]);
        $one = $this->person('یک‌سده‌ای فیلتر آزمایشی', ['death_year_hijri' => 1080]);
        $ids = [$two->id, $set->id, $one->id];
        $found = fn (array $query) => SearchFilters::fromRequest(Request::create('/search', 'GET', $query), 'authors')->apply(Person::query())
            ->whereIn('people.id', $ids)->orderBy('people.id')->pluck('people.id')->all();

        $this->assertSame([$set->id], $found(['century' => 9]));
        $this->assertSame([$two->id, $set->id], $found(['century' => 10]));
        $this->assertSame([$two->id, $one->id], $found(['century' => 11]));
        $this->assertSame([$two->id, $set->id], $found(['century' => 10, 'estimates' => 1]));
        $this->assertSame([], $found(['century' => 12]));

        $this->assertStringContainsString('life_centuries = 10', SearchFilters::fromRequest(Request::create('/search', 'GET', ['century' => 10]), 'authors')->engine());
        $this->assertSame([[10, 11], 11, 1050], [$two->toSearchableArray()['life_centuries'], $two->toSearchableArray()['century_hijri'], $two->toSearchableArray()['era_year']]);
        $this->assertSame([9, 10], $set->toSearchableArray()['life_centuries']);
        $this->assertSame([11], $one->toSearchableArray()['life_centuries']);
        $this->assertContains('life_centuries', config('scout.meilisearch.index-settings.' . Person::class . '.filterableAttributes'));

        // the search page, by the database when the engine is not there
        $this->get(route('search', ['type' => 'authors', 'century' => 10]))->assertOk()->assertSee('سدۀ ۱۰ (زندگی)');

        // the century of the death alone, which the chart of authors by death links to (step 14.271)
        $this->assertSame([$set->id], $found(['death_century' => 10]));
        $this->assertSame([$two->id, $one->id], $found(['death_century' => 11]));
        $this->assertStringContainsString('(century_hijri = 11)', SearchFilters::fromRequest(Request::create('/search', 'GET', ['death_century' => 11]), 'authors')->engine());
        $this->get(route('search', ['type' => 'authors', 'death_century' => 11]))->assertOk()->assertSee('سدۀ ۱۱ (وفات)')
            ->assertSee('name="death_century" value="11"', false);
        $this->assertStringContainsString('death_century', file_get_contents(resource_path('views/insights/charts/authors.blade.php')));
    }

    public function test_the_review_queues_find_a_group_under_each_century_of_its_people()
    {
        $two = $this->person('دوسده‌ای صف آزمایشی', ['birth_year_hijri' => 970, 'death_year_hijri' => 1050]);
        $one = $this->person('یک‌سده‌ای صف آزمایشی', ['death_year_hijri' => 1080]);
        $review = \App\Models\PersonReview::create(['type' => 'duplicate', 'key' => 'lc-' . uniqid(), 'person_ids' => [$two->id, $one->id], 'status' => 'open', 'reason' => 'test']);
        $queue = new \App\Review\NamesakeQueue;
        $found = fn (int $century) => $queue->filter(\App\Models\PersonReview::query()->whereKey($review->id), ['century' => $century])->count();

        $this->assertSame([0, 1, 1, 0], [$found(9), $found(10), $found(11), $found(12)]);
        \Illuminate\Support\Facades\Cache::forget('collective-review.' . $queue->key() . '.filters');
        $options = $queue->filterOptions()['century']['options'];
        $this->assertArrayHasKey(10, $options);
        $this->assertArrayHasKey(11, $options);
    }

    public function test_the_backfill_fills_the_rows_written_before_the_column_and_keeps_a_reviewed_one()
    {
        $row = fn (string $name, array $columns) => DB::table('people')->insertGetId(['name' => $name, 'normalized_name' => $name, 'slug' => 'lc-' . uniqid()] + $columns);
        $two = $row('دوسده‌ای پیشین آزمایشی', ['birth_year_hijri' => 970, 'death_year_hijri' => 1050, 'century_hijri' => 11]);
        $one = $row('یک‌سده‌ای پیشین آزمایشی', ['birth_year_hijri' => 1010, 'death_year_hijri' => 1080, 'century_hijri' => 11]);
        $stale = $row('کهنۀ آزمایشی', ['birth_year_hijri' => 1010, 'death_year_hijri' => 1080, 'century_hijri' => 11, 'century_from' => 10]);
        $set = $row('ثبت‌شدۀ آزمایشی', ['death_year_hijri' => 940, 'century_hijri' => 10, 'century_from' => 9]);
        $reviewed = $row('بازبینی‌شدۀ آزمایشی', ['birth_year_hijri' => 905, 'death_year_hijri' => 960, 'century_hijri' => 10, 'century_from' => 9]);
        AuditLog::create(['auditable_type' => Person::class, 'auditable_id' => $reviewed, 'action' => 'verify', 'new_values' => ['century_from' => 9]]);
        $from = fn () => DB::table('people')->whereIn('id', [$two, $one, $stale, $set, $reviewed])->orderBy('id')->pluck('century_from')->all();

        $this->artisan('fahares:fill-life-centuries')->expectsOutputToContain('Dry run, nothing written')->assertSuccessful();
        $this->assertSame([null, null, 10, 9, 9], $from());

        $this->artisan('fahares:fill-life-centuries', ['--apply' => true])->assertSuccessful();
        $this->assertSame([10, null, null, 9, 9], $from());
        $this->artisan('fahares:fill-life-centuries', ['--apply' => true])->expectsOutputToContain('0 first centuries filled, 0 emptied')->assertSuccessful();
    }
}
