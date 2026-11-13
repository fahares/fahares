<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Support\DateEstimates\EstimateText;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The estimated date of composition on the page of a work with no date (step 14.170)
 */
class CompositionEstimateTest extends TestCase
{
    private Library $library;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانه‌ی تخمین آزمایشی', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
    }

    private function work(string $title, ?int $year = null): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'estimate-' . uniqid(), 'volume_number' => 1, 'page_start' => 1,
            'composition_date_raw' => $year ? "{$year}ق" : null, 'composition_year_hijri' => $year]);
        DB::table('works')->where('id', $work->id)->update(['composition_date_from' => $year, 'composition_date_to' => $year]);

        return $work;
    }

    private function copy(Work $work, int $year): Manuscript
    {
        $n = ++$this->sequence;
        $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => $n, 'volume_number' => 1, 'page_start' => 1, 'library_id' => $this->library->id,
            'library' => $this->library->name, 'city' => 'قم', 'shelfmark' => "تخمین {$n}", 'raw_text' => "{$n}. قم", 'copy_date_raw' => "{$year}ق"]);
        DB::table('manuscripts')->where('id', $copy->id)->update(['copy_date_from' => $year, 'copy_date_to' => $year]);

        return $copy;
    }

    public function test_an_undated_work_shows_its_range_marked_as_an_estimate_with_the_reasons()
    {
        $author = Person::create(['name' => 'مؤلف آزمایشی تخمین‌نما', 'normalized_name' => 'مؤلف آزمایشی تخمین‌نما', 'slug' => 'estimate-' . uniqid(), 'birth_year_hijri' => 1000]);
        $work = $this->work('اثر بی‌تاریخ تخمین‌نما');
        DB::table('work_people')->insert(['work_id' => $work->id, 'person_id' => $author->id, 'role' => 'author', 'position' => 0]);
        $this->copy($work, 1090);
        $oldest = $this->copy($work, 1060);
        $dated = $this->work('اثر تاریخ‌دار تخمین‌نما', 1050);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $estimate = EstimateText::for('work', $work->id, 'composition');
        $this->assertSame('میان 1015 و 1060 ق', $estimate['text']);
        $this->assertSame(['پس از 1015', 'پیش از 1060'], array_column($estimate['reasons'], 'bound'));
        $this->assertSame(['مؤلف در این سال 15 ساله بوده است:', 'کهن‌ترین نسخه‌ی تاریخ‌دار اثر در این سال کتابت شده است:'], array_column($estimate['reasons'], 'text'));

        $this->get(route('works.show', $work))->assertOk()
            ->assertSeeInOrder(['تاریخ تألیف', 'میان ۱۰۱۵ و ۱۰۶۰ ق', 'تخمینی', 'چرا؟', 'پس از ۱۰۱۵', 'مؤلف آزمایشی تخمین‌نما', 'پیش از ۱۰۶۰', 'ش تخمین ۲'])
            ->assertSee(route('manuscripts.show', $oldest->id), false);

        // a documented date stands alone
        $this->assertNull(EstimateText::for('work', $dated->id, 'composition'));
        $this->get(route('works.show', $dated))->assertOk()->assertSee('۱۰۵۰ق')->assertDontSee('تخمینی');
    }

    public function test_one_bound_or_a_range_too_wide_reads_as_before_or_after()
    {
        // only a copy: before it
        $copied = $this->work('اثر تنها با نسخه');
        $this->copy($copied, 1200);
        // a commentary on a work of 800 with a copy of 1100: three centuries, so only the later bound is shown
        $base = $this->work('متن آزمایشی کهن', 800);
        $commentary = $this->work('شرح بی‌تاریخ آزمایشی');
        DB::table('work_relations')->insert(['work_id' => $commentary->id, 'related_work_id' => $base->id, 'type' => 'commentary', 'source' => 'auto']);
        $this->copy($commentary, 1100);
        // a commentary with no copy: after its base
        $bare = $this->work('شرح بی‌نسخه‌ی آزمایشی');
        DB::table('work_relations')->insert(['work_id' => $bare->id, 'related_work_id' => $base->id, 'type' => 'commentary', 'source' => 'auto']);
        $nothing = $this->work('اثر بی‌قرینه‌ی آزمایشی');

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $this->assertSame('پیش از 1200 ق', EstimateText::for('work', $copied->id, 'composition')['text']);
        $wide = EstimateText::for('work', $commentary->id, 'composition');
        $this->assertSame(['پیش از 1100 ق', 1], [$wide['text'], count($wide['reasons'])]);
        $after = EstimateText::for('work', $bare->id, 'composition');
        $this->assertSame(['پس از 800 ق', 'اثری که این اثر بر پایه‌ی آن است در این سال تألیف شده است:', 'متن آزمایشی کهن'],
            [$after['text'], $after['reasons'][0]['text'], $after['reasons'][0]['title']]);
        $this->assertNull(EstimateText::for('work', $nothing->id, 'composition'));

        $this->get(route('works.show', $copied))->assertOk()->assertSee('پیش از ۱۲۰۰ ق')->assertSee('تخمینی');
        // nothing to estimate: the row stays hidden outside suggestion mode, as before
        $this->get(route('works.show', $nothing))->assertOk()->assertDontSee('تخمینی');
    }
}
