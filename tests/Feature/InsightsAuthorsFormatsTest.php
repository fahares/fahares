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

    public function test_the_cities_of_the_periods_of_lives_are_counted_by_kind_and_shown_with_links()
    {
        $period = fn (Person $of, string $kind, string $place, string $status = \App\Models\PersonPeriod::APPROVED) => \App\Models\PersonPeriod::create(
            ['person_id' => $of->id, 'kind' => $kind, 'place' => $place, 'status' => $status]);
        [$one, $two, $outside] = [$this->author('دانشور یکم'), $this->author('دانشور دوم'), $this->author('دانشور بیرون دامنه')];
        $this->copy($this->work('اثر دانشور یکم', $one));
        $this->copy($this->work('اثر دانشور دوم', $two));
        $period($one, 'study', 'نجف');
        $period($one, 'study', 'نجف اشرف');
        $period($one, 'residence', 'اصفهان');
        $period($two, 'study', 'نجف');
        $period($two, 'teaching', 'نجف');
        $period($two, 'death', 'نجف', \App\Models\PersonPeriod::PROPOSED);
        $period($outside, 'study', 'نجف');

        // In a scope: its authors and scribes, each once for a city and a kind, and only what is approved
        $cities = $this->figures()['life_cities'];
        $this->assertSame([['نجف', 2, ['study' => 2, 'teaching' => 1]], ['اصفهان', 1, ['residence' => 1]]],
            array_map(fn ($c) => [$c['name'], $c['people'], $c['kinds']], $cities['cities']));
        $this->assertSame([2, 5, 2, 0], [$cities['people'], $cities['periods'], $cities['all_cities'], $cities['kinds']['death']]);
        // With no scope everyone with a period counts
        $all = collect(Insights::build()['life_cities']['cities'])->firstWhere('name', 'نجف');
        $this->assertGreaterThanOrEqual(3, $all['kinds']['study']);

        $figures = $this->figures();
        $this->assertStringContainsString('پرشمارترین شهر نجف است: دورۀ زندگی ۲ تن', InsightsNotes::for($figures)['lifeCities']['examples'][0]);
        $this->assertSame(['life-cities'], array_column(InsightsTables::for($figures)['lifeCities'], 'file'));

        $this->get(route('insights.topic', ['topic' => 'people', 'library' => $this->library->id]))->assertOk()
            ->assertSee('id="life-cities"', false)->assertSee('شهرهای زندگی دانشوران')->assertSee('data-life-city="نجف"', false)
            ->assertSee('data-insights-table="life-cities"', false);
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

    public function test_works_with_no_author_are_counted_by_subject_and_by_the_century_of_their_oldest_copy()
    {
        config(['insights.anonymous_min_works' => 2, 'insights.min_sample' => 3]);
        $deeds = Subject::create(['name' => 'اسناد آزمایشی', 'slug' => 'insights-formats-' . uniqid()]);
        $law = Subject::create(['name' => 'فقه آزمایشی بی مؤلف', 'slug' => 'insights-formats-' . uniqid()]);
        $author = $this->author('مؤلف آثار فقهی آزمایشی');
        $make = function (string $title, Subject $subject, ?Person $by, array $dates, array $attributes = []) {
            $work = $this->work($title, $by);
            $work->forceFill($attributes)->save();
            $work->subjects()->attach($subject->id);
            foreach ($dates as $date) {
                $this->copy($work, $date ? ['copy_date_raw' => $date] : []);
            }

            return $work;
        };
        // Deeds: three nameless works of the thirteenth century, each of one copy
        foreach (['سند یکم', 'سند دوم', 'سند سوم'] as $title) {
            $make($title, $deeds, null, ['1250ق']);
        }
        // Law: two works of a known author, one nameless, and one whose author is named with no record of a person
        $make('کتاب فقهی یکم', $law, $author, ['1050ق', '1260ق']);
        $make('کتاب فقهی دوم', $law, $author, ['1070ق']);
        $make('رسالۀ فقهی بی نام', $law, null, ['1080ق', null]);
        $make('رسالۀ مؤلف بی رکورد', $law, null, ['1090ق'], ['author_name' => 'محمد جعفر']);

        $figures = $this->figures();
        $an = $figures['anonymous'];
        $century = fn (int $c) => collect($an['centuries'])->firstWhere('century', $c);

        $this->assertSame([7, 1], [$an['works'], $an['unlinked']]);
        $this->assertSame(['works' => 4, 'copies' => 5, 'single' => 3, 'copied' => 4], $an['nameless']);
        $this->assertSame(['works' => 3, 'copies' => 4, 'single' => 2, 'copied' => 3], $an['authored']);
        $this->assertSame([['اسناد آزمایشی', 3, 3, 100.0], ['فقه آزمایشی بی مؤلف', 4, 1, 25.0]],
            array_map(fn ($s) => [$s['name'], $s['works'], $s['anonymous'], $s['share']], $an['subjects']));
        // The work copied in 1050 and in 1260 is of the eleventh century, the century of its oldest copy
        $this->assertSame(['century' => 11, 'works' => 4, 'anonymous' => 1, 'share' => 25.0], $century(11));
        $this->assertSame(['century' => 13, 'works' => 3, 'anonymous' => 3, 'share' => 100.0], $century(13));
        $this->assertSame(['century' => 12, 'works' => 0, 'anonymous' => 0, 'share' => null], $century(12));

        $note = InsightsNotes::for($figures)['anonymous'];
        $this->assertStringContainsString('از ۷ اثر این دامنه، ۴ اثر (۵۷٫۱٪) مؤلفی ندارند؛ ۱ اثر دیگر نام مؤلف دارند', $note['examples'][0]);
        $this->assertStringContainsString('«اسناد آزمایشی» بیشترین سهم اثر بی مؤلف را دارد (۱۰۰٪ از ۳ اثر)', $note['examples'][3]);
        $this->assertSame(['anonymous-by-subject', 'anonymous-by-century'], array_column(InsightsTables::for($figures)['anonymous'], 'file'));

        $this->get(route('insights.topic', ['topic' => 'works', 'library' => $this->library->id]))->assertOk()
            ->assertSee('id="anonymous"', false)
            ->assertSee('آثار بی مؤلف')
            ->assertSee('data-insights-table="anonymous-by-subject"', false);
    }

    public function test_autographs_and_the_authors_who_copied_the_works_of_others_are_counted()
    {
        config(['insights.author_scribe_min_works' => 2]);
        $scholar = $this->author('دانشمند کاتب آزمایشی', 1110);
        $once = $this->author('کاتب تک‌اثر آزمایشی');
        $other = $this->author('مؤلف دیگر آزمایشی');
        $plain = Person::create(['name' => 'کاتب سادۀ آزمایشی', 'normalized_name' => 'کاتب سادۀ آزمایشی', 'slug' => 'insights-formats-' . uniqid(), 'is_scribe' => true]);
        $own = $this->work('اثر دانشمند', $scholar);
        $second = $this->work('اثر دوم دانشمند', $scholar);
        $theirs = $this->work('اثر مؤلف دیگر', $other);
        // In his own hand: the scribe is the author, whether the catalogue says so or not
        $this->copy($own, ['scribe_id' => $scholar->id, 'is_autograph' => true]);
        $this->copy($own, ['scribe_id' => $scholar->id]);
        // The catalogue calls it an autograph and names no scribe: the author's
        $this->copy($theirs, ['is_autograph' => true]);
        // Called an autograph under a scribe who is not the author: in `flagged` alone
        $this->copy($theirs, ['scribe_id' => $plain->id, 'is_autograph' => true]);
        // The scholar copies another's work, three times; a plain scribe is no author
        foreach (range(1, 3) as $i) {
            $this->copy($theirs, ['scribe_id' => $scholar->id]);
        }
        $this->copy($second, ['scribe_id' => $plain->id]);
        // An author of one work who copies another's: counted, but not listed among the scholars
        $this->work('تنها اثر کاتب', $once);
        $this->copy($theirs, ['scribe_id' => $once->id]);

        $figures = $this->figures();
        $ag = $figures['autographs'];

        $this->assertSame([3, 3, 2, 2], [$ag['flagged'], $ag['copies'], $ag['works'], $ag['authors']]);
        $this->assertSame([['دانشمند کاتب آزمایشی', 1110, 2, 1, 2], ['مؤلف دیگر آزمایشی', null, 1, 1, 1]],
            array_map(fn ($r) => [$r['name'], $r['death'], $r['copies'], $r['works'], $r['of_works']], $ag['items']));
        $this->assertSame([2, 2, 4], [$ag['author_scribes'], $ag['other_scribes'], $ag['other_copies']]);
        $this->assertSame([['دانشمند کاتب آزمایشی', 3, 2]], array_map(fn ($r) => [$r['name'], $r['others'], $r['own']], $ag['others']));

        $note = InsightsNotes::for($figures)['autographs'];
        $this->assertStringContainsString('۳ نسخه به خط مؤلف خود است، از ۲ اثر و ۲ مؤلف', $note['examples'][0]);
        $this->assertStringContainsString('۲ نسخه به خط خودش، از ۱ اثر، در حالی که ۲ اثر به نامش ثبت است', $note['examples'][1]);
        $this->assertSame(['autographs', 'author-scribes'], array_column(InsightsTables::for($figures)['autographs'], 'file'));

        $this->get(route('insights.topic', ['topic' => 'people', 'library' => $this->library->id]))->assertOk()
            ->assertSee('id="autographs"', false)
            ->assertSee('نسخه‌های خط مؤلف و مؤلفان کاتب')
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'author_id' => $scholar->id, 'scribe_id' => $scholar->id, 'library_id' => $this->library->id])), false)
            ->assertSee('data-insights-table="autographs"', false);
    }
}
