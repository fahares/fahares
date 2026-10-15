<?php

namespace Tests\Feature;

use App\Http\Controllers\SearchController;
use App\Models\Manuscript;
use App\Models\Work;
use Tests\TestCase;

class WorkSearchTextsTest extends TestCase
{
    protected array $workIds = [];

    protected function tearDown(): void
    {
        Manuscript::whereIn('work_id', $this->workIds)->delete();
        Work::whereIn('id', $this->workIds)->delete();

        parent::tearDown();
    }

    protected function work(array $attrs = []): Work
    {
        $work = Work::create($attrs + [
            'primary_title' => 'اثر آزمایشی نمایه ' . uniqid(),
            'clean_title' => 'اثر آزمایشی نمایه',
            'slug' => 'test-search-texts-' . uniqid(),
            'volume_number' => 1,
            'page_start' => 1,
        ]);
        $this->workIds[] = $work->id;

        return $work;
    }

    protected function manuscript(Work $work, ?string $incipit, ?string $explicit = null): Manuscript
    {
        return Manuscript::create([
            'work_id' => $work->id,
            'sequence_number' => 1,
            'volume_number' => 1,
            'page_start' => 1,
            'incipit_text' => $incipit,
            'explicit_text' => $explicit,
            'raw_text' => 'نسخه‌ی آزمایشی',
        ]);
    }

    public function test_manuscript_texts_are_distinct_and_skip_cross_references_and_the_works_own()
    {
        $work = $this->work(['incipit_text' => 'الحمد لله رب العالمین']);
        $this->manuscript($work, 'الحمد لله رب العالمین', 'تمت الرساله');
        $this->manuscript($work, 'بسمله و الحمد لله الذی', 'تمت الرساله');
        $this->manuscript($work, 'برابر1', 'برابر 2');
        $this->manuscript($work, null, 'و صلی الله علی محمد');

        $texts = $work->fresh()->manuscriptSearchTexts();

        $this->assertSame('تمت الرساله', $texts[0], 'the most frequent text comes first');
        $this->assertCount(3, $texts);
        $this->assertNotContains('الحمد لله رب العالمین', $texts);
        $this->assertEmpty(array_filter($texts, fn ($t) => str_starts_with($t, 'برابر')));
        $this->assertArrayHasKey('manuscript_texts', $work->fresh()->toSearchableArray());
    }

    public function test_manuscript_texts_are_capped()
    {
        $work = $this->work();
        for ($i = 0; $i < Work::SEARCH_MANUSCRIPT_TEXTS_MAX + 5; $i++) {
            $this->manuscript($work, "آغاز شماره {$i}");
        }
        $this->assertCount(Work::SEARCH_MANUSCRIPT_TEXTS_MAX, $work->fresh()->manuscriptSearchTexts());

        $long = $this->work();
        foreach (range(1, 5) as $i) {
            $this->manuscript($long, str_repeat("متن بلند {$i} ", 600));
        }
        $chars = array_sum(array_map('mb_strlen', $long->fresh()->manuscriptSearchTexts()));
        $this->assertLessThanOrEqual(Work::SEARCH_MANUSCRIPT_TEXTS_MAX_CHARS, $chars);
    }

    public function test_match_reason_explains_hits_outside_titles_and_names()
    {
        $reason = fn (array $fields) => (new \ReflectionMethod(SearchController::class, 'workMatchReason'))->invoke(null, $fields);

        $this->assertNull($reason([]));
        $this->assertNull($reason(['primary_title', 'manuscript_texts']));
        $this->assertSame('یافته در آغاز یا انجام اثر', $reason(['incipit_text', 'manuscript_texts']));
        $this->assertSame('یافته در آغاز یا انجام نسخه‌ها', $reason(['manuscript_texts[3]']));
        $this->assertSame('یافته در معرفی اثر', $reason(['description']));
    }
}
