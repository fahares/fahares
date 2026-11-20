<?php

namespace Tests\Feature;

use App\Console\Commands\FirsthandCoverageCommand;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use App\Support\FirsthandCoverage;
use App\Support\Insights;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The first-hand coverage on the insights page and the library pages (step 14.256) */
class FirsthandCoveragePageTest extends TestCase
{
    use DatabaseTransactions;

    private function library(string $name): Library
    {
        return Library::create(['name' => $name, 'city' => 'قم', 'slug' => 'firsthand-' . uniqid()]);
    }

    private function report(array $libraries): array
    {
        return [
            'measured_at' => now()->toIso8601String(),
            'total' => ['copies' => 1000, 'with_text' => 300, 'was_read' => 250, 'complete' => 120, 'fields' => []],
            'libraries' => $libraries,
        ];
    }

    private function row(Library $library, int $copies, int $read, int $complete): array
    {
        return ['library_id' => $library->id, 'name' => $library->name, 'city' => $library->city, 'copies' => $copies,
            'with_text' => $read, 'was_read' => $read, 'complete' => $complete];
    }

    protected function tearDown(): void
    {
        Cache::forget(FirsthandCoverageCommand::CACHE_KEY);
        parent::tearDown();
    }

    public function test_the_pages_show_the_share_of_copies_that_stand_on_the_library_catalogue()
    {
        $read = $this->library('کتابخانۀ آزمایشی سنجیده');
        $unread = $this->library('کتابخانۀ آزمایشی نسنجیده');
        Cache::forever(FirsthandCoverageCommand::CACHE_KEY, $this->report([
            $this->row($read, 200, 180, 164), $this->row($unread, 50, 0, 0),
        ]));
        // the page shows its sections only when it has copies to count
        $work = Work::create(['primary_title' => 'اثر آزمایشی پوشش', 'clean_title' => 'اثر آزمایشی پوشش', 'slug' => 'firsthand-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        Manuscript::create(['work_id' => $work->id, 'library_id' => $read->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        Insights::refresh();

        $this->insightsPages()->assertOk()
            ->assertSee('data-firsthand-total', false)
            ->assertSee('۱۲۰')                     // the copies that stand on their catalogue
            ->assertSee('۱۲٪')                     // of all 1,000
            ->assertSee('data-firsthand-library="' . $read->id . '"', false)
            ->assertSee('۸۲٪')                     // 164 of 200
            ->assertDontSee('data-firsthand-library="' . $unread->id . '"', false)
            ->assertSee('فهرست چاپی کتابخانه')
            ->assertDontSee('فنخا درست');

        // a page narrowed to a scope leaves the whole-site measure out
        $this->insightsPages(['library' => $read->id])->assertOk()->assertDontSee('data-firsthand-total', false);

        $this->get(route('libraries.show', $read))->assertOk()->assertSee('data-library-firsthand', false)->assertSee('۸۲٪');
        $this->get(route('libraries.show', $unread))->assertOk()->assertDontSee('data-library-firsthand', false);
    }

    public function test_an_empty_or_malformed_cache_shows_nothing()
    {
        $library = $this->library('کتابخانۀ آزمایشی بی‌گزارش');
        foreach ([null, 'not a report', ['total' => []]] as $cached) {
            $cached === null ? Cache::forget(FirsthandCoverageCommand::CACHE_KEY) : Cache::forever(FirsthandCoverageCommand::CACHE_KEY, $cached);
            $this->assertNull(FirsthandCoverage::summary());
            $this->insightsPages()->assertOk()->assertDontSee('data-firsthand-total', false);
            $this->get(route('libraries.show', $library))->assertOk()->assertDontSee('data-library-firsthand', false);
        }

        // a report where no copy was read yet shows nothing either
        Cache::forever(FirsthandCoverageCommand::CACHE_KEY, $this->report([$this->row($library, 40, 0, 0)]));
        $this->assertNull(FirsthandCoverage::summary());
        $this->assertNull(FirsthandCoverage::forLibrary($library->id));
    }

    public function test_a_library_removed_since_the_measure_drops_out()
    {
        $kept = $this->library('کتابخانۀ آزمایشی ماندگار');
        $gone = $this->library('کتابخانۀ آزمایشی ادغام‌شده');
        Cache::forever(FirsthandCoverageCommand::CACHE_KEY, $this->report([$this->row($kept, 10, 5, 5), $this->row($gone, 10, 8, 2)]));
        $gone->delete();

        $this->assertSame([$kept->id], array_column(FirsthandCoverage::summary()['libraries'], 'id'));
    }

    public function test_the_measure_runs_each_night()
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'fahares:firsthand-coverage'));
        $this->assertCount(1, $events);
        $this->assertSame('10 3 * * *', $events->first()->expression);
    }
}
