<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** The shared box of exact figures on the charts of the statistics (backlog item 156, step 14.273). */
class InsightsChartTipTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_marks_of_a_chart_carry_their_exact_figure_for_the_shared_box()
    {
        $library = Library::create(['name' => 'کتابخانۀ آزمایشی کادر', 'city' => 'قم', 'slug' => 'insights-tip-' . uniqid()]);
        $work = Work::create(['primary_title' => 'اثر آزمایشی کادر', 'clean_title' => 'اثر آزمایشی کادر', 'slug' => 'insights-tip-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        foreach (range(1, 12) as $i) {
            Manuscript::create(['work_id' => $work->id, 'sequence_number' => $i, 'volume_number' => 1, 'page_start' => 1, 'copy_date_raw' => (1040 + $i) . 'ق'])
                ->forceFill(['library_id' => $library->id])->save();
        }

        $page = $this->get(route('insights.topic', ['topic' => 'time', 'library' => $library->id]))->assertOk()
            // One box on the page, hidden until a mark is pointed at
            ->assertSee('<div id="chart-tip" data-chart-tip role="tooltip" hidden', false)
            // A column of the timeline: the figure for the box and for a screen reader, and no browser title
            ->assertSee('data-tip="سدۀ ۱۱: ۱۲ نسخه · سال معین ۱۲" aria-label="سدۀ ۱۱: ۱۲ نسخه · سال معین ۱۲"', false)
            ->assertDontSee('title="سدۀ ۱۱', false)
            // The decades are drawn in the browser, with their figure bound the same way
            ->assertSee(':data-tip="`${((sel - 1) * 100', false);
        $this->assertSame(1, substr_count($page->getContent(), 'data-chart-tip'));
    }

    public function test_no_chart_keeps_a_browser_title_for_a_figure()
    {
        foreach ([...glob(resource_path('views/insights/charts/*.blade.php')), resource_path('views/insights/century-bars.blade.php'), resource_path('views/insights/century-grid.blade.php')] as $file) {
            $view = file_get_contents($file);
            $this->assertStringNotContainsString('title="{{ $tip }}"', $view, basename($file));
            $this->assertDoesNotMatchRegularExpression('/[^-]:title="`/', $view, basename($file));
        }
        $this->assertStringContainsString("import chartTip from './chart-tip';", file_get_contents(resource_path('js/app.js')));
    }
}
