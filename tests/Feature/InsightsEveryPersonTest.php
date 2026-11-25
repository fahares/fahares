<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Support\Insights;
use App\Support\InsightsScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Every scribe of a copy and every author of a work is counted, not the first one alone (backlog item 160, step 14.391). */
class InsightsEveryPersonTest extends TestCase
{
    use DatabaseTransactions;

    private function person(string $name, array $attributes = []): Person
    {
        $person = Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'insights-every-' . uniqid()]);
        $attributes && $person->forceFill($attributes)->save();

        return $person;
    }

    public function test_a_second_scribe_and_a_second_author_are_counted_as_the_first_are()
    {
        $library = Library::create(['name' => 'کتابخانۀ آزمایشی نفر دوم', 'city' => 'قم', 'slug' => 'insights-every-' . uniqid()]);
        [$first, $second] = [$this->person('کاتب نخست آزمایشی'), $this->person('کاتب دوم آزمایشی')];
        [$author, $coauthor] = [$this->person('پدیدآور نخست آزمایشی', ['death_from' => 1090, 'death_to' => 1090]), $this->person('پدیدآور دوم آزمایشی', ['death_from' => 1120, 'death_to' => 1120])];
        $work = Work::create(['primary_title' => 'اثر آزمایشی دو پدیدآور', 'clean_title' => 'اثر آزمایشی دو پدیدآور', 'slug' => 'insights-every-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $work->forceFill(['author_id' => $author->id, 'composition_place' => 'اصفهان', 'composition_date_from' => 1080, 'composition_date_to' => 1080])->save();
        WorkPerson::firstOrCreate(['work_id' => $work->id, 'person_id' => $author->id, 'role' => WorkPerson::AUTHOR]);
        WorkPerson::create(['work_id' => $work->id, 'person_id' => $coauthor->id, 'role' => WorkPerson::AUTHOR, 'position' => 1]);
        $copies = [];
        foreach (range(1, 3) as $i) {
            $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => $i, 'volume_number' => 1, 'page_start' => 1, 'copy_place' => 'اصفهان', 'copy_date_raw' => (1040 + $i) . 'ق']);
            $copy->forceFill(['library_id' => $library->id, 'scribe_id' => $first->id])->save();
            ManuscriptPerson::firstOrCreate(['manuscript_id' => $copy->id, 'person_id' => $first->id, 'role' => ManuscriptPerson::SCRIBE]);
            $copies[] = $copy;
        }
        // The second scribe wrote two of the three copies with the first
        foreach (array_slice($copies, 0, 2) as $copy) {
            ManuscriptPerson::create(['manuscript_id' => $copy->id, 'person_id' => $second->id, 'role' => ManuscriptPerson::SCRIBE, 'position' => 1]);
        }

        $figures = Insights::build(InsightsScope::fromArray(['library' => $library->id]));

        // A copy of two scribes is one copy with a scribe, and a copy of each of the two
        $this->assertSame([3, 2], [$figures['scribes']['with_scribe'], $figures['scribes']['scribes']]);
        $this->assertSame([['کاتب نخست آزمایشی', 3, 'اصفهان'], ['کاتب دوم آزمایشی', 2, 'اصفهان']],
            array_map(fn ($s) => [$s['name'], $s['count'], $s['city']], $figures['scribes']['items']));
        // Both scribes and both authors are people of their city on the map
        $city = collect($figures['places']['cities'])->firstWhere('name', 'اصفهان');
        $this->assertSame([2, 2], [$city['scribes'], $city['authors']]);
        $this->assertSame([2, 2], [$figures['places']['scribes_mapped'], $figures['places']['authors_mapped']]);
        // Both authors in the count of authors and in the centuries of their deaths
        $this->assertSame(2, $figures['authors']['total']);
        $this->assertSame(2, $figures['authors']['counted']);
    }
}
