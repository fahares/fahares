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

/** The authors with the most works and copies, and the page of the copies (backlog item 162, step 14.314). */
class InsightsAuthorsFormatsTest extends TestCase
{
    use DatabaseTransactions;

    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانۀ آزمایشی قطع', 'city' => 'قم', 'slug' => 'insights-formats-' . uniqid()]);
    }

    private function work(string $title, ?Person $author = null): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'insights-formats-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        if ($author) {
            $work->forceFill(['author_id' => $author->id])->save();
        }

        return $work;
    }

    private function author(string $name, ?int $death = null): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'insights-formats-' . uniqid(), 'is_author' => true, 'death_year_hijri' => $death]);
    }

    private function copy(Work $work, array $attributes = []): Manuscript
    {
        $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        $copy->forceFill($attributes + ['library_id' => $this->library->id])->save();

        return $copy;
    }

    private function figures(): array
    {
        return Insights::build(InsightsScope::fromArray(['library' => $this->library->id]));
    }

    public function test_authors_are_ranked_by_their_works_and_by_their_copies()
    {
        $prolific = $this->author('مؤلف پراثر آزمایشی', 1241);
        $copied = $this->author('مؤلف پرنسخۀ آزمایشی');
        foreach (['رسالۀ یکم', 'رسالۀ دوم', 'رسالۀ سوم'] as $title) {
            $this->copy($this->work($title, $prolific));
        }
        $text = $this->work('متن درسی', $copied);
        foreach (range(1, 5) as $i) {
            $this->copy($text);
        }
        // A work with no author is in neither list
        $this->copy($this->work('اثر بی مؤلف'));

        $figures = $this->figures();
        $authors = $figures['top_authors'];
        $names = fn (string $list) => array_map(fn ($r) => [$r['name'], $r['works'], $r['copies']], $authors[$list]);

        $this->assertSame([['مؤلف پراثر آزمایشی', 3, 3], ['مؤلف پرنسخۀ آزمایشی', 1, 5]], $names('by_works'));
        $this->assertSame([['مؤلف پرنسخۀ آزمایشی', 1, 5], ['مؤلف پراثر آزمایشی', 3, 3]], $names('by_copies'));
        $this->assertSame([2, 1, 4, 1241], [$authors['authors'], $authors['single'], $authors['works'], $authors['by_works'][0]['death']]);

        $note = InsightsNotes::for($figures)['topAuthors'];
        $this->assertStringContainsString('پراثرترین مؤلف مؤلف پراثر آزمایشی است، با ۳ اثر و ۳ نسخه', $note['examples'][0]);
        $this->assertStringContainsString('پرنسخه‌ترین مؤلف مؤلف پرنسخۀ آزمایشی است، با ۵ نسخه از ۱ اثر', $note['examples'][1]);
        $this->assertSame(['authors-by-works', 'authors-by-copies'], array_column(InsightsTables::for($figures)['topAuthors'], 'file'));
    }

    public function test_copies_are_banded_by_the_height_of_their_page()
    {
        $law = Subject::create(['name' => 'فقه آزمایشی قطع', 'slug' => 'insights-formats-' . uniqid()]);
        $verse = Subject::create(['name' => 'شعر آزمایشی قطع', 'slug' => 'insights-formats-' . uniqid()]);
        $big = $this->work('کتاب بزرگ');
        $big->subjects()->attach($law->id);
        $small = $this->work('دفتر کوچک');
        $small->subjects()->attach($verse->id);
        // Ten of each, so that a century and a subject have enough copies to be shown (min_sample)
        foreach (range(1, 10) as $i) {
            $this->copy($big, ['dimensions' => '20×30سم', 'copy_date_raw' => '1100ق']);
            $this->copy($small, ['dimensions' => '11/5×17 سم', 'copy_date_raw' => '1250ق']);
        }
        // The longer side is the height whichever is written first
        $this->copy($big, ['dimensions' => '30×20سم']);
        // Left out: a slip of the catalogue, and what is not two numbers
        $this->copy($big, ['dimensions' => '20×300سم']);
        $this->copy($big, ['dimensions' => 'رقعی']);
        $this->copy($big, ['dimensions' => '13×سم']);
        $this->copy($big);

        $figures = $this->figures();
        $formats = $figures['formats'];
        $band = fn (?int $upto) => collect($formats['bands'])->firstWhere('upto', $upto);
        $century = fn (int $c) => collect($formats['centuries'])->firstWhere('century', $c);

        $this->assertSame([21, 1], [$formats['with_size'], $formats['left_out']]);
        $this->assertSame(['above' => 15, 'upto' => 18, 'count' => 10], $band(18));
        $this->assertSame(['above' => 28, 'upto' => 34, 'count' => 11], $band(34));
        $this->assertSame(0, $band(15)['count']);
        $this->assertSame(30.0, $formats['median']);
        $this->assertSame([['width' => 20.0, 'height' => 30.0, 'count' => 11], ['width' => 11.5, 'height' => 17.0, 'count' => 10]], $formats['common']);
        $this->assertSame(['century' => 11, 'height' => 30.0, 'width' => 20.0, 'count' => 10], $century(11));
        $this->assertSame(['century' => 13, 'height' => 17.0, 'width' => 11.5, 'count' => 10], $century(13));
        $this->assertSame(['century' => 12, 'height' => null, 'width' => null, 'count' => 0], $century(12));
        $this->assertSame([['فقه آزمایشی قطع', 30.0, 20.0, 11], ['شعر آزمایشی قطع', 17.0, 11.5, 10]],
            array_map(fn ($s) => [$s['name'], $s['height'], $s['width'], $s['count']], $formats['subjects']));

        $note = InsightsNotes::for($figures)['formats'];
        $this->assertStringContainsString('«بیش از ۲۸ تا ۳۴ سانتی‌متر» است: ۱۱ نسخه', $note['examples'][0]);
        $this->assertStringContainsString('پرشمارترین اندازه ۲۰ در ۳۰ سانتی‌متر است، با ۱۱ نسخه', $note['examples'][2]);
        $this->assertSame(['page-heights', 'page-sizes', 'page-by-century', 'page-by-subject'], array_column(InsightsTables::for($figures)['formats'], 'file'));
    }

    public function test_the_pages_of_people_and_of_copies_show_the_two_charts()
    {
        $one = $this->author('مؤلف یکم صفحۀ آمار');
        $two = $this->author('مؤلف دوم صفحۀ آمار');
        $first = $this->work('اثر یکم صفحۀ آمار', $one);
        $second = $this->work('اثر دوم صفحۀ آمار', $two);
        foreach (range(1, 6) as $i) {
            $this->copy($first, ['dimensions' => '15×21سم', 'copy_date_raw' => '1100ق']);
            $this->copy($second, ['dimensions' => '12/5×19سم', 'copy_date_raw' => '1100ق']);
        }
        $scope = ['library' => $this->library->id];

        $this->get(route('insights.topic', ['topic' => 'people'] + $scope))->assertOk()
            ->assertSee('id="top-authors"', false)
            ->assertSee('مؤلفان پرکار و پرنسخه')
            ->assertSee('مؤلف یکم صفحۀ آمار')
            ->assertSee(e(route('search', ['type' => 'works', 'author_id' => $one->id])), false)
            ->assertSee('data-insights-table="authors-by-copies"', false)
            ->assertDontSee('id="formats"', false);
        $this->get(route('insights.topic', ['topic' => 'copies'] + $scope))->assertOk()
            ->assertSee('id="formats"', false)
            ->assertSee('قطع نسخه‌ها')
            ->assertSee('۱۲٫۵×۱۹')
            ->assertSee('میانۀ بلندی ۱۹ سانتی‌متر است')
            ->assertSee('data-insights-table="page-heights"', false);
        // The list of authors says nothing in the scope of one author
        $this->get(route('insights.topic', ['topic' => 'people', 'author' => $one->id]))->assertOk()->assertDontSee('id="top-authors"', false);
    }
}
