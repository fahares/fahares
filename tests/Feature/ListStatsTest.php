<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Subject;
use App\Models\Work;
use App\Support\ListStats;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** The summary of a long list on a record page and its way to the insights page (backlog item 106) */
class ListStatsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['insights.list_stats_min' => 5]);
    }

    private function person(string $role = 'author'): Person
    {
        $name = 'شخص آزمایشی فهرست ' . uniqid();

        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'list-' . uniqid(), "is_{$role}" => true]);
    }

    private function work(?Person $author = null): Work
    {
        $title = 'اثر آزمایشی فهرست ' . uniqid();
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'list-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $author && $work->forceFill(['author_id' => $author->id])->save();

        return $work;
    }

    private function copies(Work $work, int $n, array $attributes = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            Manuscript::create($attributes + ['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        }
    }

    public function test_the_summary_counts_the_cities_the_centuries_and_the_features_of_the_copies()
    {
        $author = $this->person();
        $work = $this->work($author);
        $this->copies($work, 4, ['copy_place' => 'هرات', 'copy_date_raw' => '1050ق', 'is_illuminated' => true]);
        $this->copies($work, 3, ['copy_place' => 'مشهد رضوی', 'copy_date_raw' => '1250ق']);
        $this->copies($work, 2, ['copy_place' => 'مشهد', 'copy_date_raw' => 'قرن 13', 'is_autograph' => true]);
        $this->copies($work, 1, ['copy_place' => 'تهران', 'copy_date_raw' => 'قرن 12 و 13']); // across two centuries: in no column
        $this->copies($work, 1, ['copy_place' => 'اصفهان']);
        $this->copies($work, 2, ['copy_place' => 'جایی ناشناخته']);
        $this->copies($work, 2);

        $stats = ListStats::for('author', $author->id);

        $this->assertSame(15, $stats['total']);
        // «مشهد رضوی» is مشهد; the fourth city and the unknown place are left out of the three
        $this->assertSame([['city' => 'مشهد', 'count' => 5], ['city' => 'هرات', 'count' => 4], ['city' => 'اصفهان', 'count' => 1]], $stats['cities']);
        $this->assertSame(11, $stats['placed']);
        $this->assertSame([11 => 4, 13 => 5], $stats['centuries']);
        $this->assertSame(9, $stats['dated']);
        $this->assertSame(2, $stats['autograph']);
        $this->assertSame(4, $stats['illuminated']);
        $this->assertSame('۱۳', ListStats::percent(2, 15));
        $this->assertSame('۶٫۷', ListStats::percent(1, 15));
        $this->assertSame('کمتر از ۰٫۱', ListStats::percent(1, 5000));

        // The same copies under the work's own filter
        $this->assertSame(15, ListStats::for('work', $work->id)['total']);
    }

    public function test_a_short_list_has_neither_summary_nor_button()
    {
        $author = $this->person();
        $this->copies($this->work($author), 4);

        $this->assertNull(ListStats::for('author', $author->id));
        $this->assertNull(ListStats::for('nothing', $author->id));
        $this->get(route('people.show', $author))->assertOk()
            ->assertDontSee('data-list-stats-link', false)->assertDontSee('data-list-stats-pending', false);
        $this->get(route('list-stats', ['author', $author->id]))->assertNotFound();
        $this->get('/list-stats/nothing/' . $author->id)->assertNotFound();
    }

    public function test_the_page_asks_for_a_summary_not_yet_built_and_draws_one_in_the_cache()
    {
        $author = $this->person();
        $this->copies($this->work($author), 6, ['copy_place' => 'هرات', 'copy_date_raw' => '1050ق']);

        // Not built: the button, and a box that asks for the summary once the page is shown
        $this->get(route('people.show', $author))->assertOk()
            ->assertSee('آمار این فهرست')
            ->assertSee(e(route('insights', ['author' => $author->id])), false)
            ->assertSee('data-list-stats-pending="author"', false)
            ->assertDontSee('data-list-stats="author"', false);

        $this->get(route('list-stats', ['author', $author->id]))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSee('data-list-stats="author"', false)
            ->assertSee('هرات')
            ->assertSee(e(route('insights', ['author' => $author->id, 'copy_city' => 'هرات'])), false)
            ->assertSee(e(route('insights', ['author' => $author->id, 'century' => 11])), false);

        // Built: drawn with the page
        $this->get(route('people.show', $author))->assertOk()
            ->assertSee('data-list-stats="author"', false)
            ->assertDontSee('data-list-stats-pending', false);
    }

    public function test_a_person_has_one_summary_as_author_and_another_as_scribe()
    {
        $person = $this->person();
        $person->forceFill(['is_scribe' => true])->save();
        $this->copies($this->work($person), 6, ['copy_place' => 'هرات']);
        $this->copies($this->work(), 8, ['scribe_id' => $person->id, 'copy_place' => 'تبریز']);

        $this->assertSame(6, ListStats::for('author', $person->id)['total']);
        $scribed = ListStats::for('scribe', $person->id);
        $this->assertSame(8, $scribed['total']);
        $this->assertSame('تبریز', $scribed['cities'][0]['city']);

        $this->get(route('people.show', $person))->assertOk()
            ->assertSee('data-list-stats="author"', false)
            ->assertSee('data-list-stats="scribe"', false)
            ->assertSee(e(route('insights', ['scribe' => $person->id])), false);
    }

    public function test_a_work_filters_its_own_list_by_century_and_libraries_and_subjects_link_to_their_insights()
    {
        $work = $this->work();
        $library = Library::create(['name' => 'کتابخانۀ آزمایشی فهرست ' . uniqid(), 'city' => 'قم', 'slug' => 'list-' . uniqid()]);
        $subject = Subject::create(['name' => 'موضوع آزمایشی فهرست ' . uniqid(), 'slug' => 'list-' . uniqid()]);
        $child = Subject::create(['name' => 'زیرموضوع آزمایشی فهرست ' . uniqid(), 'slug' => 'list-' . uniqid(), 'parent_id' => $subject->id]);
        $work->subjects()->attach($child->id);
        $this->copies($work, 6, ['library_id' => $library->id, 'copy_date_raw' => '1050ق', 'is_autograph' => true]);
        foreach ([['work', $work->id], ['library', $library->id], ['subject', $subject->id]] as [$param, $id]) {
            ListStats::for($param, $id);
        }

        // A century and the autographs filter the work's list of copies on its own page
        $workPage = route('works.show', ['id' => $work->getRouteKey()]);
        $this->get($workPage)->assertOk()
            ->assertSee(e(route('insights', ['work' => $work->id])), false)
            ->assertSee(e($workPage . '?century=11#manuscripts'), false)
            ->assertSee(e($workPage . '?autograph=1#manuscripts'), false);

        $this->get(route('libraries.show', $library))->assertOk()
            ->assertSee('data-list-stats="library"', false)
            ->assertSee(e(route('insights', ['library' => $library->id])), false);

        // The copies of a subject's branches are its own
        $this->assertSame(6, ListStats::for('subject', $subject->id)['total']);
        $this->get(route('subjects.show', $subject))->assertOk()
            ->assertSee('data-list-stats="subject"', false)
            ->assertSee(e(route('insights', ['subject' => $subject->id])), false);
    }
}
