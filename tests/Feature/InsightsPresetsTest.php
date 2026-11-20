<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use App\Support\CopyPlaces;
use App\Support\InsightsNotes;
use App\Support\InsightsPresets;
use App\Support\InsightsScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The ready-made scopes under the filter form and the leads of the notes (backlog item 155, step 14.268). */
class InsightsPresetsTest extends TestCase
{
    use DatabaseTransactions;

    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانۀ آزمایشی دامنه‌ها', 'city' => 'قم', 'slug' => 'insights-presets-' . uniqid()]);
        config(['insights.preset_min_copies' => 3, 'insights.presets' => [
            ['label' => 'دامنۀ پرنسخه', 'topic' => 'time', 'scope' => ['library' => $this->library->slug, 'century' => '11'], 'note' => 'جملۀ دامنۀ پرنسخه'],
            ['label' => 'دامنۀ کم‌نسخه', 'scope' => ['library' => $this->library->slug, 'century' => '9'], 'note' => 'جملۀ دامنۀ کم‌نسخه'],
            ['label' => 'دامنۀ بی رکورد', 'scope' => ['library' => 'no-such-library', 'century' => '11'], 'note' => 'جملۀ دامنۀ بی رکورد'],
        ]]);
        Cache::flush();
    }

    private function copies(int $n, string $date): void
    {
        $work = Work::create(['primary_title' => 'اثر آزمایشی دامنه‌ها', 'clean_title' => 'اثر آزمایشی دامنه‌ها', 'slug' => 'insights-presets-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        foreach (range(1, $n) as $i) {
            Manuscript::create(['work_id' => $work->id, 'sequence_number' => $i, 'volume_number' => 1, 'page_start' => 1, 'copy_date_raw' => $date])
                ->forceFill(['library_id' => $this->library->id])->save();
        }
    }

    public function test_a_preset_is_shown_only_with_its_records_and_enough_copies()
    {
        $this->copies(4, '1040ق');
        $this->copies(2, '840ق');

        $presets = InsightsPresets::all();

        $this->assertSame([['label' => 'دامنۀ پرنسخه', 'note' => 'جملۀ دامنۀ پرنسخه', 'topic' => 'time',
            'query' => ['library' => $this->library->id, 'century' => '11'], 'count' => 4]], $presets);
        // A filter that names nothing does not widen the scope: the preset is left out
        $this->assertNull(InsightsPresets::scope(['library' => 'no-such-library', 'century' => '11']));
        $this->assertNull(InsightsPresets::scope(['copy_city' => 'شهری که نیست']));
    }

    public function test_the_whole_pages_list_the_presets_and_a_scoped_page_does_not()
    {
        $this->copies(4, '1040ق');
        $link = route('insights.topic', ['topic' => 'time', 'library' => $this->library->id, 'century' => '11']);

        foreach ([route('insights'), route('insights.topic', 'places')] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('چند دامنه برای دیدن')
                ->assertSee('دامنۀ پرنسخه')
                ->assertSee('جملۀ دامنۀ پرنسخه')
                ->assertSee('href="' . e($link) . '"', false)
                ->assertDontSee('دامنۀ کم‌نسخه')
                ->assertDontSee('دامنۀ بی رکورد');
        }
        $this->get($link)->assertOk()->assertDontSee('چند دامنه برای دیدن');
    }

    public function test_the_nightly_build_builds_the_presets_shown()
    {
        $this->copies(4, '1040ق');
        $scope = InsightsScope::fromArray(['library' => $this->library->id, 'century' => '11']);
        $this->assertFalse(\App\Support\Insights::isCached($scope));

        $this->assertSame(1, InsightsPresets::warm());

        $this->assertTrue(\App\Support\Insights::isCached($scope));
    }

    public function test_the_presets_of_the_site_are_well_formed()
    {
        // The file itself: setUp replaced the presets of the config
        $presets = (require config_path('insights.php'))['presets'];

        $this->assertNotEmpty($presets);
        $this->assertSame(count($presets), count(array_unique(array_column($presets, 'label'))));
        foreach ($presets as $preset) {
            $this->assertNotSame('', trim($preset['note']), $preset['label']);
            $this->assertStringNotContainsString("ه\u{200C}ی ", $preset['label'] . ' ' . $preset['note'] . ' ');
            $this->assertSame([], array_diff(array_keys($preset['scope']), InsightsScope::PARAMS), $preset['label']);
            $this->assertTrue(! isset($preset['topic']) || isset(config('insights.topics')[$preset['topic']]), $preset['label']);
            if (isset($preset['scope']['copy_city'])) {
                $this->assertTrue(CopyPlaces::isCity($preset['scope']['copy_city']), $preset['label']);
            }
            // The filters that need no record of the data make a scope as they stand
            $plain = array_intersect_key($preset['scope'], array_flip(['copy_city', 'century', 'facsimile']));
            $this->assertCount(count($plain), InsightsScope::fromArray($plain)->values, $preset['label']);
        }
    }

    public function test_the_second_paragraph_of_every_note_opens_with_a_lead()
    {
        $this->copies(12, '1040ق');
        $notes = InsightsNotes::for(\App\Support\Insights::build(InsightsScope::fromArray(['library' => $this->library->id])));

        foreach ($notes as $chart => $note) {
            $this->assertMatchesRegularExpression('/[؟.]$/u', $note['lead'], $chart);
            $this->assertCount(2, $note['reading'], $chart);
        }
        $this->assertSame('چرا ستون سده‌های نخستین کوتاه است؟', $notes['timeline']['lead']);
        $this->assertSame('منبع.', $notes['sources']['lead']);

        $this->get(route('insights.topic', ['topic' => 'time', 'library' => $this->library->id]))->assertOk()
            ->assertSee('<strong class="font-bold text-ink" data-insights-lead>چرا ستون سده‌های نخستین کوتاه است؟</strong>', false);
    }
}
