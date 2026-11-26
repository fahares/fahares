<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Support\MaintenanceNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Backlog item 136, part 4 (step 14.414): the advance notice of a planned maintenance. */
class MaintenanceNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        MaintenanceNotice::clear();
        parent::tearDown();
    }

    public function test_a_solar_hijri_start_is_read_in_tehran_time(): void
    {
        // 6 Azar 1405 = 27 Nov 2026; 02:00 in Tehran (UTC+3:30) is 22:30 UTC the day before
        $this->assertSame('2026-11-26 22:30', MaintenanceNotice::parseStart('1405/09/06 02:00')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-26 22:30', MaintenanceNotice::parseStart('۱۴۰۵-۰۹-۰۶ ۰۲:۰۰')->format('Y-m-d H:i'));
    }

    public function test_a_bare_time_is_its_next_coming(): void
    {
        $now = CarbonImmutable::parse('2026-11-26 10:00', 'UTC'); // 13:30 in Tehran
        $this->assertSame('2026-11-26 11:30', MaintenanceNotice::parseStart('15:00', $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-26 22:30', MaintenanceNotice::parseStart('02:00', $now)->format('Y-m-d H:i'));
    }

    public function test_a_bad_start_is_refused(): void
    {
        $this->artisan('fahares:maintenance-notice', ['start' => 'فردا'])->assertFailed();
        $this->artisan('fahares:maintenance-notice', ['start' => '1405/13/01 02:00'])->assertFailed();
        $this->assertNull(MaintenanceNotice::current());
    }

    public function test_every_page_shows_the_notice_until_the_maintenance_is_over(): void
    {
        $at = CarbonImmutable::now()->addHours(3);
        MaintenanceNotice::set($at, 45, 'جست‌وجو هم در این مدت در دسترس نیست.');

        $page = $this->get('/');
        $page->assertOk();
        $page->assertSee('data-maintenance-notice', false);
        $page->assertSee('حدود ۴۵ دقیقه');
        $page->assertSee('جست‌وجو هم در این مدت در دسترس نیست.');
        $this->assertSame(0, Announcement::count(), 'no Telegram notice without --telegram');

        $this->travelTo($at->addMinutes(46));
        $this->get('/')->assertDontSee('data-maintenance-notice', false);
    }

    public function test_clear_takes_the_notice_down(): void
    {
        MaintenanceNotice::set(CarbonImmutable::now()->addHour(), 30);
        $this->artisan('fahares:maintenance-notice', ['--clear' => true])->assertSuccessful();
        $this->get('/')->assertDontSee('data-maintenance-notice', false);
    }

    public function test_telegram_posts_once_and_edits_the_same_post_when_moved(): void
    {
        config(['notifications.telegram.bot_token' => 'test-token']);
        // A year before 2038, the end of a TIMESTAMP column (ends_at)
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);

        $this->artisan('fahares:maintenance-notice', ['start' => '1414/09/30 02:00', '--minutes' => 20, '--telegram' => true])
            ->assertSuccessful();
        $a = Announcement::sole();
        $this->assertFalse($a->on_site);
        $this->assertSame(77, (int) $a->telegram_message_id);
        $this->assertStringContainsString('۳۰ آذر ۱۴۱۴', $a->body);

        $this->artisan('fahares:maintenance-notice', ['start' => '1414/09/30 03:00', '--minutes' => 20, '--telegram' => true])
            ->assertSuccessful();
        $this->assertSame(1, Announcement::count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], '۰۳:۰۰'));
    }
}
