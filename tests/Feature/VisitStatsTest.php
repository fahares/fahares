<?php

namespace Tests\Feature;

use App\Auth\Permissions;
use App\Models\Library;
use App\Models\User;
use App\Models\Work;
use App\Support\PageViews;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Visit stats (backlog item 103) */
class VisitStatsTest extends TestCase
{
    private const BROWSER = ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0', 'Accept-Language' => 'fa-IR,fa;q=0.9'];

    private const IP = '203.0.113.47';

    private string $buffer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buffer = sys_get_temp_dir() . '/page-views-test-' . uniqid();
        config(['visits.buffer' => $this->buffer]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->buffer);
        parent::tearDown();
    }

    private function visit(string $url, array $headers = [], string $ip = self::IP): \Illuminate\Testing\TestResponse
    {
        // The test client keeps the headers of earlier requests
        return $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($headers + self::BROWSER)->get($url);
    }

    private function noted(): string
    {
        return implode('', array_map('file_get_contents', glob($this->buffer . '/views-*') ?: []));
    }

    private function library(): Library
    {
        return Library::create(['name' => 'کتابخانه‌ی آزمایشی بازدید', 'city' => 'قم', 'slug' => 'visit-library-' . uniqid()]);
    }

    public function test_a_page_view_is_noted_after_the_response_without_the_ip_and_loaded_later(): void
    {
        $library = $this->library();

        $this->visit(route('libraries.show', $library), ['Referer' => 'https://www.google.com/search?q=x'])->assertOk();
        $this->visit(route('libraries.show', $library), [
            'Referer' => route('home'),
            'User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Mobile Safari/537.36',
        ])->assertOk();

        // Noted in the buffer, not yet in the table
        $this->assertSame(0, DB::table('page_views')->count());
        $this->assertStringNotContainsString(self::IP, $this->noted());
        $this->assertSame(2, PageViews::flush(true));

        $views = DB::table('page_views')->orderBy('id')->get();
        $this->assertCount(2, $views);
        $this->assertSame(['library', (string) $library->id, 'google.com', 0, 0],
            [$views[0]->type, $views[0]->ref, $views[0]->referrer, (int) $views[0]->is_mobile, (int) $views[0]->is_member]);
        $this->assertSame([PageViews::SELF, 1], [$views[1]->referrer, (int) $views[1]->is_mobile]);
        $this->assertMatchesRegularExpression('~^[0-9a-f]{16}$~', $views[0]->visitor);
        $this->assertNotSame($views[0]->visitor, $views[1]->visitor, 'another browser is another visitor');
        foreach ($views as $view) {
            $this->assertStringNotContainsString(self::IP, json_encode($view));
        }
        $this->assertSame('', $this->noted(), 'the loaded files are removed');
    }

    public function test_bots_prefetches_scripts_the_panel_and_failed_pages_are_not_counted(): void
    {
        $library = $this->library();
        $admin = User::create(['name' => 'مدیر بازدید', 'email' => 'visit-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $page = route('libraries.show', $library);

        $this->visit($page, ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])->assertOk();
        $this->visit($page, ['User-Agent' => 'curl/8.5.0'])->assertOk();
        $this->visit($page, ['Accept-Language' => ''])->assertOk();
        $this->visit($page, ['Sec-Purpose' => 'prefetch'])->assertOk();
        $this->visit($page, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->visit($page, [], '127.0.0.1')->assertOk();
        $this->visit(route('libraries.show', 999999999))->assertNotFound();
        $this->actingAs($admin);
        $this->visit(route('admin.visit-stats.index'))->assertOk();

        $this->assertSame('', $this->noted());
    }

    public function test_a_visitor_is_the_same_within_a_day_and_another_the_next_day(): void
    {
        $library = $this->library();
        $page = route('libraries.show', $library);

        $this->travelTo(now(PageViews::TIMEZONE)->setTime(10, 0));
        $this->visit($page)->assertOk();
        $this->visit($page)->assertOk();
        $this->visit($page, [], '198.51.100.9')->assertOk();
        $this->travel(1)->days();
        $this->visit($page)->assertOk();
        PageViews::flush(true);

        $visitors = DB::table('page_views')->orderBy('id')->pluck('visitor');
        $this->assertSame($visitors[0], $visitors[1]);
        $this->assertNotSame($visitors[0], $visitors[2]);
        $this->assertNotSame($visitors[0], $visitors[3], 'the salt changes each day');
        $this->assertSame([basename(glob($this->buffer . '/salt-*')[0])], ['salt-' . PageViews::today()], 'the past day\'s salt is deleted');
    }

    public function test_the_nightly_command_folds_the_past_days_and_prunes_the_old_views(): void
    {
        $day = fn (int $ago) => now(PageViews::TIMEZONE)->subDays($ago)->toDateString();
        $view = fn (int $ago, string $type, string $ref, string $visitor, ?string $referrer = null, bool $mobile = false) => [
            'day' => $day($ago), 'type' => $type, 'ref' => $ref, 'visitor' => $visitor, 'referrer' => $referrer, 'is_mobile' => $mobile, 'is_member' => false];
        DB::table('page_views')->insert([
            $view(40, 'work', '7', 'a'),
            $view(2, 'work', '7', 'a', 'google.com'),
            $view(2, 'work', '7', 'a', PageViews::SELF),
            $view(2, 'work', '7', 'b', null, true),
            $view(2, 'manuscript', '9', 'b', PageViews::SELF, true),
            $view(1, 'work', '7', 'c'),
            $view(0, 'work', '7', 'd'),
        ]);

        $this->artisan('fahares:aggregate-page-views')->assertSuccessful();

        $daily = fn (int $ago, string $type, string $ref) => DB::table('page_view_daily')->where('day', $day($ago))->where('type', $type)->where('ref', $ref)->first();
        $this->assertSame([3, 2], [(int) $daily(2, 'work', '7')->views, (int) $daily(2, 'work', '7')->visitors]);
        $this->assertSame([4, 2], [(int) $daily(2, PageViews::SITE, '')->views, (int) $daily(2, PageViews::SITE, '')->visitors]);
        $this->assertSame(2, (int) $daily(2, '_device', 'mobile')->views);
        $this->assertSame(1, (int) $daily(2, '_referrer', 'google.com')->views);
        $this->assertSame(2, (int) $daily(2, '_referrer', PageViews::SELF)->views);
        $this->assertSame(1, (int) $daily(40, 'work', '7')->views);
        $this->assertNull($daily(0, 'work', '7'), 'today is not over');
        $this->assertSame(0, DB::table('page_views')->where('day', $day(40))->count(), 'older than 30 days, and folded');
        $this->assertSame(6, DB::table('page_views')->count());

        // Run again, nothing is counted twice; the record's total adds today's single views
        $this->artisan('fahares:aggregate-page-views')->assertSuccessful();
        $this->assertSame(3, (int) $daily(2, 'work', '7')->views);
        $this->assertSame(6, PageViews::total('work', '7'));
    }

    public function test_the_stats_page_shows_trend_referrers_and_top_records_to_those_allowed(): void
    {
        $work = Work::create(['primary_title' => 'اثر پربازدید آزمون', 'clean_title' => 'اثر پربازدید آزمون', 'slug' => 'visit-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 10]);
        $today = PageViews::today();
        DB::table('page_views')->insert([
            ['day' => $today, 'type' => 'work', 'ref' => (string) $work->id, 'visitor' => 'a', 'referrer' => 'google.com', 'is_mobile' => true, 'is_member' => false],
            ['day' => $today, 'type' => 'work', 'ref' => (string) $work->id, 'visitor' => 'b', 'referrer' => 'example.org', 'is_mobile' => false, 'is_member' => true],
            ['day' => $today, 'type' => 'source_page', 'ref' => 'monzavi/5/3366', 'visitor' => 'a', 'referrer' => null, 'is_mobile' => true, 'is_member' => false],
        ]);

        $admin = User::create(['name' => 'مدیر بازدید', 'email' => 'visit-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.visit-stats.index'))->assertOk()
            ->assertSee('بازدید در هر روز')
            ->assertSee('جست‌وجوی Google')->assertSee('example.org')
            ->assertSeeInOrder(['پربازدیدترین آثار', 'اثر پربازدید آزمون', 'صفحه‌های منبع پربازدید', 'ج ۵، ص ۳۳۶۶'])
            ->assertSee(route('admin.search-stats.index', ['days' => 30]), false);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee(route('admin.visit-stats.index'), false);
        // The record's page tells its admins its views
        $this->actingAs($admin)->get(route('works.show', $work))->assertSee('۲ بازدید');

        $editor = User::create(['name' => 'ویراستار بازدید', 'email' => 'visit-editor@fahares.net', 'password' => 'password123', 'role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.visit-stats.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('works.show', $work))->assertDontSee('۲ بازدید');

        Permissions::save(['editor' => array_merge(Permissions::defaults()['editor'], ['visits.stats'])]);
        $this->actingAs($editor)->get(route('admin.visit-stats.index'))->assertOk();
    }
}
