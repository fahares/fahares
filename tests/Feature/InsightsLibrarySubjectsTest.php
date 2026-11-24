<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Subject;
use App\Models\Work;
use App\Support\Insights;
use App\Support\InsightsNotes;
use App\Support\InsightsScope;
use App\Support\InsightsTables;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** The subjects each library is strong in (backlog item 162, step 14.343), read in the scope of one author. */
class InsightsLibrarySubjectsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_library_is_strong_in_the_subject_it_holds_more_of_than_the_others()
    {
        config(['insights.specialty_min_copies' => 4, 'insights.specialty_min_cell' => 2, 'insights.min_sample' => 3]);
        $author = Person::create(['name' => 'مؤلف آزمایشی تخصص', 'normalized_name' => 'مؤلف آزمایشی تخصص', 'slug' => 'insights-ls-' . uniqid(), 'is_author' => true]);
        $library = fn (string $name) => Library::create(['name' => $name, 'city' => 'قم', 'slug' => 'insights-ls-' . uniqid()]);
        [$medical, $legal, $small] = [$library('کتابخانۀ پزشکی آزمایشی'), $library('کتابخانۀ فقهی آزمایشی'), $library('کتابخانۀ کوچک آزمایشی')];
        $work = function (string $title, ?string $subject) use ($author) {
            $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'insights-ls-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
            $work->forceFill(['author_id' => $author->id])->save();
            if ($subject) {
                $work->subjects()->attach(Subject::create(['name' => $subject, 'slug' => 'insights-ls-' . uniqid()])->id);
            }

            return $work;
        };
        $copies = function (Work $work, Library $library, int $n) {
            foreach (range(1, $n) as $i) {
                $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
                $copy->forceFill(['library_id' => $library->id])->save();
            }
        };
        $medicine = $work('کتاب طبی آزمایشی', 'طب آزمایشی تخصص');
        $law = $work('کتاب فقهی آزمایشی', 'فقه آزمایشی تخصص');
        // Medicine: 6 of the 8 copies of one library, 2 of the 10 of the other; 8 of all 18
        $copies($medicine, $medical, 6);
        $copies($law, $medical, 2);
        $copies($medicine, $legal, 2);
        $copies($law, $legal, 8);
        // A library with too few copies, and copies of a work with no subject, are not counted
        $copies($medicine, $small, 3);
        $copies($work('کتاب بی موضوع', null), $medical, 5);

        $figures = Insights::build(InsightsScope::fromArray(['author' => $author->id]));
        $ls = $figures['library_subjects'];

        $this->assertSame([21, 2], [$ls['copies'], $ls['libraries']]);
        // The share in all the libraries reads the small one too: medicine is 11 of 21
        $this->assertSame([
            ['کتابخانۀ فقهی آزمایشی', 10, [['فقه آزمایشی تخصص', 8, 80.0, 47.6, 1.7]]],
            ['کتابخانۀ پزشکی آزمایشی', 8, [['طب آزمایشی تخصص', 6, 75.0, 52.4, 1.4]]],
        ], array_map(fn ($l) => [$l['name'], $l['copies'], array_map(fn ($s) => [$s['name'], $s['count'], $s['share'], $s['overall'], $s['ratio']], $l['subjects'])], $ls['items']));
        $this->assertSame([['طب آزمایشی تخصص', 'کتابخانۀ پزشکی آزمایشی', 75.0], ['فقه آزمایشی تخصص', 'کتابخانۀ فقهی آزمایشی', 80.0]],
            array_map(fn ($s) => [$s['name'], $s['library']['name'], $s['share']], $ls['subjects']));

        $note = InsightsNotes::for($figures)['librarySubjects'];
        $this->assertStringContainsString('در کتابخانۀ فقهی آزمایشی، ۸۰٪ نسخه‌ها از «فقه آزمایشی تخصص» است (۸ نسخه)', $note['examples'][0]);
        $this->assertSame(['library-subjects', 'subject-libraries'], array_column(InsightsTables::for($figures)['librarySubjects'], 'file'));

        $this->get(route('insights.topic', ['topic' => 'libraries', 'author' => $author->id]))->assertOk()
            ->assertSee('id="library-subjects"', false)
            ->assertSee('موضوع‌های پررنگ هر کتابخانه')
            ->assertSee('۱٫۷ برابر')
            ->assertSee('data-insights-table="library-subjects"', false);
        // In the scope of one library there is nothing to compare it with
        $this->get(route('insights.topic', ['topic' => 'libraries', 'library' => $legal->id]))->assertOk()->assertDontSee('id="library-subjects"', false);
    }
}
