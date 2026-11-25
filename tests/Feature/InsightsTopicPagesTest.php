<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use App\Support\Insights;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Arr;
use Tests\TestCase;

/** The hub of the statistics and its topic pages (backlog item 153, step 14.263). */
class InsightsTopicPagesTest extends TestCase
{
    use DatabaseTransactions;

    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانۀ آزمایشی صفحه‌های آمار', 'city' => 'قم', 'slug' => 'insights-topics-' . uniqid()]);
        $work = Work::create(['primary_title' => 'اثر آزمایشی صفحه‌های آمار', 'clean_title' => 'اثر آزمایشی صفحه‌های آمار', 'slug' => 'insights-topics-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        foreach (range(1, 12) as $i) {
            Manuscript::create(['work_id' => $work->id, 'sequence_number' => $i, 'volume_number' => 1, 'page_start' => 1, 'copy_date_raw' => '1100ق', 'copy_place' => 'هرات'])
                ->forceFill(['library_id' => $this->library->id])->save();
        }
    }

    public function test_every_chart_file_is_on_one_topic_page_and_every_listed_chart_has_its_file()
    {
        $listed = Arr::flatten(array_column(config('insights.topics'), 'charts'));
        $files = array_map(fn ($path) => basename($path, '.blade.php'), glob(resource_path('views/insights/charts/*.blade.php')));

        $this->assertSame([], array_diff($listed, $files));
        $this->assertSame([], array_diff($files, $listed));
        $this->assertSame($listed, array_unique($listed));
    }

    public function test_the_hub_has_the_cards_and_no_chart_and_sends_an_old_chart_link_to_its_page()
    {
        Insights::refresh();
        $hub = $this->get(route('insights'))->assertOk()
            ->assertSee('data-insights-cards', false)
            // Each topic's card has its own sign (step 14.398)
            ->assertSeeInOrder(array_map(fn ($slug) => 'data-insights-icon="' . $slug . '"', array_keys(config('insights.topics'))), false)
            ->assertDontSee('data-insights-note', false)
            ->assertDontSee('id="timeline"', false)
            // The page of each chart by its id, for a link that came with the chart's anchor
            ->assertSee('location.replace(pages[id]', false)
            ->assertSee('copy-gap', false);
        foreach (config('insights.topics') as $slug => $topic) {
            $hub->assertSee(e(route('insights.topic', $slug)), false)->assertSee($topic['lead']);
        }
    }

    public function test_a_topic_page_has_its_own_charts_only()
    {
        Insights::refresh();
        $this->get(route('insights.topic', 'time'))->assertOk()
            ->assertSee('کتابت و تألیف در گذر سده‌ها')
            ->assertSee('id="timeline"', false)
            ->assertSee('id="copy-gap"', false)
            ->assertDontSee('id="places"', false)
            ->assertDontSee('data-insights-cards', false);
        // The sign of the topic over its heading, and a sign on each of the six tabs (step 14.400)
        $page = $this->get(route('insights.topic', 'time'))->getContent();
        $this->assertSame(2, substr_count($page, 'data-insights-icon="time"'));
        $this->assertSame(7, substr_count($page, 'data-insights-icon="'));
        $this->get(route('insights.topic', 'places'))->assertOk()
            ->assertSee('id="places"', false)
            ->assertSee('id="movement"', false)
            ->assertDontSee('id="timeline"', false);
        $this->get('/insights/no-such-topic')->assertNotFound();
        // The choices of the filter form keep their own address beside the topics
        $this->get(route('insights.lookup', ['field' => 'subject']))->assertOk()->assertJsonIsArray();
    }

    public function test_the_scope_stays_through_the_tabs_the_chips_and_the_form()
    {
        $scope = ['library' => $this->library->id, 'century' => '11'];
        $this->get(route('insights.topic', ['topic' => 'places'] + $scope))->assertOk()
            ->assertSee('کانون‌های استنساخ و تألیف در نسخه‌های')
            // The tabs and the hub take the scope along
            ->assertSee(e(route('insights', $scope)), false)
            ->assertSee(e(route('insights.topic', ['topic' => 'time'] + $scope)), false)
            // Taking one filter off, and the form, stay on this page
            ->assertSee(e(route('insights.topic', ['topic' => 'places', 'century' => '11'])), false)
            ->assertSee('action="' . e(route('insights.topic', 'places')) . '"', false)
            ->assertSee('name="robots" content="noindex, follow"', false);
        $this->get(route('insights', $scope))->assertOk()
            ->assertSee(e(route('insights.topic', ['topic' => 'places'] + $scope)), false);
    }
}
