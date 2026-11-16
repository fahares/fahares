<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Support\Insights;
use App\Support\InsightsScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** The figures of backlog item 146 (step 14.228), each read in the scope of a library made for the test. */
class InsightsMoreFiguresTest extends TestCase
{
    use DatabaseTransactions;

    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانه‌ی آزمایشی آمار', 'city' => 'قم', 'slug' => 'insights-more-' . uniqid()]);
    }

    private function work(string $title, array $attributes = []): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'insights-more-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $work->forceFill($attributes)->save();

        return $work;
    }

    private function author(): Person
    {
        $name = 'مؤلف آزمایشی آمار ' . uniqid();

        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'insights-more-' . uniqid(), 'is_author' => true]);
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

    public function test_works_are_banded_by_their_copies()
    {
        $much = $this->work('اثر سه‌نسخه‌ای');
        $this->copy($much);
        $this->copy($much);
        $this->copy($much);
        $this->copy($this->work('اثر تک‌نسخه‌ی یکم'));
        $this->copy($this->work('اثر تک‌نسخه‌ی دوم'));

        $figures = $this->figures()['copies_per_work'];
        $band = fn (?int $upto) => collect($figures['bands'])->firstWhere('upto', $upto);

        $this->assertSame(3, $figures['works']);
        $this->assertSame(5, $figures['copies']);
        $this->assertSame(2, $figures['single']);
        $this->assertSame(['above' => 0, 'upto' => 1, 'works' => 2, 'copies' => 2], $band(1));
        $this->assertSame(['above' => 2, 'upto' => 5, 'works' => 1, 'copies' => 3], $band(5));
        // The most-copied hundredth is one work at least: it holds three of the five copies
        $this->assertSame(['percent' => 1, 'works' => 1, 'copies' => 3, 'share' => 60.0], $figures['top'][0]);
    }

    public function test_each_century_names_its_most_copied_works()
    {
        $early = $this->work('اثر رایج سده‌ی یازدهم');
        $late = $this->work('اثر رایج سده‌ی سیزدهم');
        $generic = $this->work('اشعار');
        foreach (['1010ق', '1020ق', '1250ق'] as $date) {
            $this->copy($early, ['copy_date_raw' => $date]);
        }
        foreach (['1040ق', '1210ق', '1220ق', '1230ق'] as $date) {
            $this->copy($late, ['copy_date_raw' => $date]);
        }
        foreach (['1001ق', '1002ق', '1003ق', '1004ق'] as $date) {
            $this->copy($generic, ['copy_date_raw' => $date]);
        }
        // A copy with no date is in no century
        $this->copy($early);

        $figures = $this->figures()['century_works'];
        $century = fn (int $c) => collect($figures['centuries'])->firstWhere('century', $c);

        $this->assertSame(11, $figures['counted']);
        $this->assertSame(7, $century(11)['count']);
        $this->assertSame([['اثر رایج سده‌ی یازدهم', 2], ['اثر رایج سده‌ی سیزدهم', 1]], array_map(fn ($w) => [$w['title'], $w['count']], $century(11)['items']));
        $this->assertSame([['اثر رایج سده‌ی سیزدهم', 3], ['اثر رایج سده‌ی یازدهم', 1]], array_map(fn ($w) => [$w['title'], $w['count']], $century(13)['items']));
        $this->assertSame([], $century(12)['items']);
    }

    public function test_much_copied_works_with_no_note_of_a_printing_are_listed()
    {
        $author = $this->author();
        $works = [
            'اثر بی نشانی چاپ' => ['author_id' => $author->id],
            'اثر چاپ‌شده' => ['author_id' => $author->id, 'print_info' => 'تهران، سنگی، 1270ق'],
            'اثری که متنش از چاپ می‌گوید' => ['author_id' => $author->id, 'raw_text' => 'این رساله در نجف به چاپ رسیده است.'],
            'اثر بی‌پدیدآور' => [],
        ];
        foreach ($works as $title => $attributes) {
            $work = $this->work($title, $attributes);
            $this->copy($work);
            $this->copy($work);
        }
        // One copy alone does not make a much-copied work
        $this->copy($this->work('اثر تک‌نسخه‌ی بی چاپ', ['author_id' => $author->id]));

        $figures = $this->figures()['unprinted'];

        $this->assertSame(['اثر بی نشانی چاپ'], array_column($figures['items'], 'title'));
        $this->assertSame(2, $figures['items'][0]['count']);
        $this->assertSame($author->name, $figures['items'][0]['author']);
        $this->assertSame(1, $figures['printed']);
        $this->assertSame(20.0, $figures['coverage']);
    }

    public function test_bound_volumes_are_counted_with_the_works_bound_together()
    {
        $first = $this->work('رساله‌ی هم‌مجلد یکم');
        $second = $this->work('رساله‌ی هم‌مجلد دوم');
        $third = $this->work('رساله‌ی هم‌مجلد سوم');
        foreach (['100', '200', '300'] as $key) {
            $this->copy($first, ['collection_key' => $key]);
            $this->copy($second, ['collection_key' => $key]);
        }
        $this->copy($third, ['collection_key' => '200']);
        // A volume with one treatise in the scope, a copy bound with nothing, and the same key in another library
        $this->copy($third, ['collection_key' => '900']);
        $this->copy($third);
        $other = Library::create(['name' => 'کتابخانه‌ی دیگر آمار', 'city' => 'قم', 'slug' => 'insights-more-' . uniqid()]);
        $this->copy($third, ['collection_key' => '100', 'library_id' => $other->id]);

        $figures = $this->figures()['collections'];
        $band = fn (?int $upto) => collect($figures['bands'])->firstWhere('upto', $upto);

        $this->assertSame(3, $figures['volumes']);
        $this->assertSame(7, $figures['copies']);
        $this->assertSame(1, $figures['lone']);
        $this->assertSame(3, $figures['largest']);
        $this->assertSame(2.3, $figures['mean']);
        $this->assertSame(77.8, $figures['coverage']);
        $this->assertSame(2, $band(2)['volumes']);
        $this->assertSame(1, $band(5)['volumes']);
        // The third work is with each of the others in one volume only, which is no pattern
        $this->assertCount(1, $figures['pairs']);
        $this->assertSame(3, $figures['pairs'][0]['count']);
        $this->assertEqualsCanonicalizing(['رساله‌ی هم‌مجلد یکم', 'رساله‌ی هم‌مجلد دوم'], array_column($figures['pairs'][0]['works'], 'title'));
    }

    public function test_copies_are_counted_by_the_catalogues_that_describe_them()
    {
        $source = fn (string $kind, string $title) => Source::create(['code' => 'insights-more-' . uniqid(), 'kind' => $kind, 'rank' => 10, 'title' => $title]);
        $old = $source(Source::KIND_LIBRARY_CATALOGUE, 'فهرست کهنه‌ی آزمایشی');
        $new = $source(Source::KIND_LIBRARY_CATALOGUE, 'فهرست تازه‌ی آزمایشی');
        $rejected = $source(Source::KIND_LIBRARY_CATALOGUE, 'فهرست ردشده‌ی آزمایشی');
        $union = $source(Source::KIND_UNION_CATALOGUE, 'فهرستگان آزمایشی');
        $entry = fn (Manuscript $copy, Source $in, array $attributes = []) => SourceEntry::create($attributes + ['citable_type' => Manuscript::class,
            'citable_id' => $copy->id, 'source_id' => $in->id, 'volume_number' => 1, 'page_start' => 5, 'origin' => SourceEntry::ORIGIN_MATCH]);

        $work = $this->work('اثر آزمایشی هم‌پوشانی');
        $twice = $this->copy($work);
        $once = $this->copy($work);
        $none = $this->copy($work);
        foreach ([$twice, $once, $none] as $copy) {
            $entry($copy, $union);
        }
        $entry($twice, $old);
        // Two entries of one catalogue are one catalogue
        $entry($twice, $new);
        $entry($twice, $new, ['page_start' => 9]);
        $entry($twice, $rejected, ['status' => SourceEntry::STATUS_REJECTED]);
        $entry($once, $old);

        $figures = $this->figures()['source_overlap'];

        $this->assertSame([0 => 1, 1 => 1, 2 => 1, 3 => 0], $figures['counts']);
        $this->assertSame(2, $figures['described']);
        $this->assertSame(1, $figures['several']);
        $this->assertCount(1, $figures['pairs']);
        $this->assertSame(1, $figures['pairs'][0]['count']);
        $this->assertEqualsCanonicalizing([$old->code, $new->code], array_column($figures['pairs'][0]['sources'], 'key'));
    }

    public function test_every_chart_has_a_note_and_a_thin_scope_breaks_none()
    {
        // One copy with nothing known about it: the notes of a scope with next to no figures
        $this->copy($this->work('اثر تنهای آزمایشی'));
        $notes = \App\Support\InsightsNotes::for($this->figures());

        $this->assertCount(24, $notes);
        foreach ($notes as $chart => $note) {
            $this->assertNotEmpty($note['reading'], $chart);
            $this->assertIsList($note['examples']);
        }
    }

    public function test_a_note_gives_examples_from_the_figures_of_its_chart()
    {
        $first = $this->work('رساله‌ی نمونه‌ی یکم');
        $second = $this->work('رساله‌ی نمونه‌ی دوم');
        foreach (['100', '200', '300'] as $key) {
            $this->copy($first, ['collection_key' => $key, 'copy_date_raw' => '1100ق']);
            $this->copy($second, ['collection_key' => $key]);
        }
        $this->copy($first);

        $notes = \App\Support\InsightsNotes::for($this->figures());

        $this->assertStringContainsString('«رساله‌ی نمونه‌ی یکم» و «رساله‌ی نمونه‌ی دوم» در ۳ مجموعه با هم آمده‌اند', implode(' ', $notes['collections']['examples']));
        $this->assertStringContainsString('پرنسخه‌ترین اثر «رساله‌ی نمونه‌ی یکم» است، با ۴ نسخه', implode(' ', $notes['topWorks']['examples']));
        $this->assertStringContainsString('بلندترین ستون سده‌ی ۱۱ است، با ۳ نسخه', implode(' ', $notes['timeline']['examples']));
    }

    public function test_the_page_shows_the_five_figures()
    {
        $first = $this->work('رساله‌ی صفحه‌ی آمار یکم', ['author_id' => $this->author()->id]);
        $second = $this->work('رساله‌ی صفحه‌ی آمار دوم', ['author_id' => $this->author()->id]);
        $catalogue = Source::create(['code' => 'insights-more-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10, 'title' => 'فهرست آزمایشی صفحه‌ی آمار']);
        // Enough copies of one century for the scope's charts to be drawn (min_sample)
        foreach (['100', '200', '300', '400', '500', '600'] as $key) {
            foreach ([$first, $second] as $work) {
                $copy = $this->copy($work, ['collection_key' => $key, 'copy_date_raw' => '1100ق']);
                SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $copy->id, 'source_id' => $catalogue->id,
                    'volume_number' => 1, 'page_start' => 5, 'origin' => SourceEntry::ORIGIN_MATCH]);
            }
        }

        $this->get(route('insights', ['library' => $this->library->id]))->assertOk()
            ->assertSee('هر اثر چند نسخه دارد')
            ->assertSee('پرنسخه‌ترین آثار هر سده')
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'work_id' => $first->id, 'century' => 11, 'library_id' => $this->library->id])), false)
            ->assertSee('آثار پرنسخه‌ی بی نشانی چاپ')
            ->assertSee('مجموعه‌ها: رساله‌های هم‌مجلد')
            ->assertSee('هر نسخه در چند فهرست وصف شده')
            ->assertSee('این نمودار را چگونه بخوانیم')
            ->assertSee('نمونه از همین ارقام')
            ->assertSee('بلندترین ستون سده‌ی ۱۱ است، با ۱۲ نسخه');
    }
}
