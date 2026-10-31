<?php

namespace Tests\Feature\Notifications;

use App\Listeners\NotifyNewDeviceSignIn;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\User;
use App\Models\Work;
use App\Notifications\DailyDigest;
use App\Notifications\Notice;
use App\Notifications\NotificationPreferences;
use App\Notifications\SystemNotices;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EventNoticesTest extends TestCase
{
    private function user(string $role = 'user', array $extra = []): User
    {
        return User::create($extra + ['name' => "کاربر {$role}", 'email' => $role . '-' . uniqid() . '@example.com', 'password' => 'password123', 'role' => $role]);
    }

    private function manuscript(): Manuscript
    {
        $work = Work::create(['primary_title' => 'اثر آزمون اعلان', 'clean_title' => 'اثر آزمون اعلان', 'slug' => 'notice-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create(['work_id' => $work->id, 'shelfmark' => '۱', 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
    }

    private function sentTypes(User $user): array
    {
        return Notification::sent($user, Notice::class)->map->type()->all();
    }

    public function test_a_role_change_tells_the_admins_and_the_user_whatever_path_made_it(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $user = $this->user();

        $user->update(['role' => 'editor']);
        $this->assertContains('user.role_changed', $this->sentTypes($admin));
        $this->assertContains('account.role', $this->sentTypes($user));

        // Verified as a scholar: only the user hears
        $user->update(['is_verified_scholar' => true]);
        $this->assertCount(2, Notification::sent($user, Notice::class));
    }

    public function test_security_notices_reach_the_old_address_and_cannot_be_switched_off(): void
    {
        Notification::fake();
        $user = $this->user();
        $old = $user->email;
        NotificationPreferences::set($user, 'account.security', 'mail', false);
        $this->assertTrue(NotificationPreferences::enabled($user, 'account.security', 'mail'));

        $user->update(['email' => 'moved-' . uniqid() . '@example.com']);
        Notification::assertSentTo($user, Notice::class, fn (Notice $n, array $channels) => $n->type() === 'account.security' && $channels === ['database', 'mail']);
        Notification::assertSentOnDemand(Notice::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $old);

        $user->update(['password' => 'another-password']);
        $this->assertCount(2, Notification::sent($user, Notice::class));

        // The settings page shows it fixed, and an email footer cannot unsubscribe from it
        $this->actingAs($user)->get(route('notifications.settings'))->assertSee('اعلان امنیتی؛ خاموش نمی‌شود.');
    }

    public function test_a_panel_user_hears_of_a_second_browser_but_not_of_the_first_or_a_known_one(): void
    {
        Notification::fake();
        $admin = $this->user('admin', ['password' => 'password123']);
        $login = fn (array $cookies = []) => $this->withCookies($cookies)->post('/login', ['email' => $admin->email, 'password' => 'password123']);

        $first = $login();
        $token = collect($first->headers->getCookies())->first(fn ($c) => $c->getName() === NotifyNewDeviceSignIn::COOKIE)?->getValue();
        $this->assertNotNull($token);
        $this->assertNotContains('account.new_device', $this->sentTypes($admin));

        auth()->logout();
        $plain = \Illuminate\Cookie\CookieValuePrefix::remove(decrypt($token, false));
        $this->withCookies([NotifyNewDeviceSignIn::COOKIE => $plain])->post('/login', ['email' => $admin->email, 'password' => 'password123']);
        $this->assertNotContains('account.new_device', $this->sentTypes($admin));

        auth()->logout();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->withHeaders(['User-Agent' => 'Another browser'])->post('/login', ['email' => $admin->email, 'password' => 'password123']);
        Notification::assertSentTo($admin, Notice::class, fn (Notice $n, array $channels) => $n->type() === 'account.new_device' && $channels === ['database', 'mail']);
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('known_devices')->where('user_id', $admin->id)->count());

        // An ordinary user is not tracked
        $plainUser = $this->user('user', ['password' => 'password123']);
        auth()->logout();
        $this->post('/login', ['email' => $plainUser->email, 'password' => 'password123']);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('known_devices')->where('user_id', $plainUser->id)->count());
    }

    public function test_sign_ins_on_the_server_and_a_browsers_simultaneous_requests_are_not_announced(): void
    {
        Notification::fake();
        $admin = $this->user('admin', ['password' => 'password123']);
        \Illuminate\Support\Facades\DB::table('known_devices')->insert(['user_id' => $admin->id, 'token_hash' => 'x', 'user_agent' => 'Old browser', 'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek(), 'last_seen_at' => now()->subWeek()]);

        // tinker or a smoke check on the server
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        event(new \Illuminate\Auth\Events\Login('web', $admin, false));
        $this->app['env'] = $env;
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('known_devices')->where('user_id', $admin->id)->count());

        // Two requests of one new browser, neither carrying the other's cookie: one notice
        foreach ([1, 2] as $i) {
            auth()->logout();
            $this->flushSession();
            $this->defaultCookies = [];
            $this->withHeaders(['User-Agent' => 'New browser'])->post('/login', ['email' => $admin->email, 'password' => 'password123']);
        }
        Notification::assertSentToTimes($admin, Notice::class, 1);
    }

    public function test_queue_and_search_failures_alert_the_admins_once_an_hour(): void
    {
        Notification::fake();
        Cache::flush();
        $admin = $this->user('admin');
        $editor = $this->user('editor');

        SystemNotices::queueFailed('App\Notifications\SuggestionSubmitted', new \RuntimeException('SMTP down'));
        SystemNotices::queueFailed('App\Notifications\SuggestionSubmitted', new \RuntimeException('SMTP down'));
        SystemNotices::searchFailed(new \RuntimeException('Meilisearch unreachable'));

        $this->assertSame(['system.queue_failed', 'system.search_failed'], $this->sentTypes($admin));
        $this->assertSame([], $this->sentTypes($editor));
    }

    public function test_a_failure_that_goes_on_is_told_again_after_growing_waits_and_its_recovery_once(): void
    {
        Notification::fake();
        Cache::flush();
        $admin = $this->user('admin');
        $fail = fn () => SystemNotices::searchFailed(new \RuntimeException('Meilisearch unreachable'));

        $fail();
        $this->travel(61)->minutes();
        $fail();
        $this->travel(3)->hours();
        $fail();
        $this->assertCount(2, $this->sentTypes($admin));
        $this->travel(62)->minutes();
        $fail();
        $this->assertCount(3, $this->sentTypes($admin));

        $this->mock(\Meilisearch\Client::class, fn ($m) => $m->shouldReceive('isHealthy')->andReturn(true));
        SystemNotices::checkSearchRecovered();
        $this->assertCount(3, $this->sentTypes($admin), 'a failure in the last ten minutes');
        $this->travel(11)->minutes();
        SystemNotices::checkSearchRecovered();
        SystemNotices::checkSearchRecovered();
        Notification::assertSentToTimes($admin, Notice::class, 4);
        Notification::assertSentTo($admin, Notice::class, fn (Notice $n) => $n->heading === 'جست‌وجو دوباره کار می‌کند');

        // The next failure starts afresh, at the first wait
        $fail();
        Notification::assertSentToTimes($admin, Notice::class, 5);
    }

    public function test_the_end_of_a_heavy_command_is_announced_only_for_listed_commands(): void
    {
        $this->assertTrue(SystemNotices::isHeavy('scout:import'));
        $this->assertTrue(SystemNotices::isHeavy('catalogs:link-volumes'));
        $this->assertFalse(SystemNotices::isHeavy('queue:work'));

        Notification::fake();
        $admin = $this->user('admin');
        SystemNotices::commandFinished('scout:import "App\Models\Work"', 1, 120);
        Notification::assertSentTo($admin, Notice::class, fn (Notice $n, array $channels) => $n->type() === 'system.command_finished' && $channels === ['database'] && str_contains($n->heading, 'شکست'));

        // A dry run, or a short successful run, is not announced; a long one is
        SystemNotices::commandFinished('sources:link-items --dry-run', 1, 900);
        SystemNotices::commandFinished('sources:link-items 26', 0, 120);
        Notification::assertSentToTimes($admin, Notice::class, 1);
        SystemNotices::commandFinished('scout:import "App\Models\Work"', 0, 900);
        Notification::assertSentTo($admin, Notice::class, fn (Notice $n) => str_contains($n->heading, 'پایان دستور'));
    }

    public function test_editing_a_record_with_pending_suggestions_tells_the_other_reviewers(): void
    {
        Notification::fake();
        Cache::flush();
        $admin = $this->user('admin');
        $other = $this->user('admin');
        $m = $this->manuscript();
        FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place', 'suggested_value' => 'هرات', 'status' => 'pending']);

        AuditLog::create(['user_id' => $admin->id, 'auditable_type' => Manuscript::class, 'auditable_id' => $m->id, 'action' => 'update', 'old_values' => [], 'new_values' => []]);
        $this->assertContains('review.conflict', $this->sentTypes($other));
        $this->assertNotContains('review.conflict', $this->sentTypes($admin));

        // A record without pending suggestions, or a second edit within the hour, sends nothing more
        AuditLog::create(['user_id' => $admin->id, 'auditable_type' => Manuscript::class, 'auditable_id' => $m->id, 'action' => 'update', 'old_values' => [], 'new_values' => []]);
        AuditLog::create(['user_id' => $admin->id, 'auditable_type' => Manuscript::class, 'auditable_id' => $this->manuscript()->id, 'action' => 'update', 'old_values' => [], 'new_values' => []]);
        $this->assertCount(1, Notification::sent($other, Notice::class));
    }

    public function test_the_weekly_reminder_lists_old_pending_suggestions(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $this->artisan('notifications:stale-suggestions')->assertSuccessful();
        Notification::assertNothingSentTo($admin);

        $m = $this->manuscript();
        $s = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place', 'suggested_value' => 'هرات', 'status' => 'pending']);
        $s->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->artisan('notifications:stale-suggestions')->assertSuccessful();
        Notification::assertSentTo($admin, Notice::class, fn (Notice $n) => $n->type() === 'review.stale' && str_contains($n->lines[0], '1 پیشنهاد'));
    }

    public function test_the_daily_digest_gathers_opinions_and_the_chosen_types(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $plain = $this->user();
        NotificationPreferences::set($admin, 'user.registered', 'digest', true);
        $m = $this->manuscript();
        FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place', 'suggested_value' => 'هرات', 'status' => 'pending', 'review_queue' => 'namesakes', 'review_item_id' => 5, 'user_id' => $plain->id]);

        $this->artisan('notifications:digest')->assertSuccessful();
        Notification::assertSentTo($admin, DailyDigest::class, fn (DailyDigest $d) => isset($d->sections['نظرهای تصحیح جمعی'], $d->sections['کاربران تازه']) && ! isset($d->sections['پیشنهادهای تازه']));
        Notification::assertNotSentTo($plain, DailyDigest::class);
    }

    public function test_the_daily_digest_gathers_the_admins_technical_notices(): void
    {
        $admin = $this->user('admin');
        foreach (['شکست دستور: sources:segment', 'شکست دستور: sources:segment', 'خطای نمایه‌ی جست‌وجو'] as $i => $title) {
            $admin->notifications()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => Notice::class,
                'data' => ['type' => $i < 2 ? 'system.command_finished' : 'system.search_failed', 'title' => $title, 'body' => '', 'url' => '/']]);
        }
        $this->assertFalse(NotificationPreferences::enabled($admin, 'system.queue_failed', 'mail'));

        Notification::fake();
        $this->artisan('notifications:digest')->assertSuccessful();
        Notification::assertSentTo($admin, DailyDigest::class, fn (DailyDigest $d) => ($d->sections['دستورهای سرور'] ?? null) === ['شکست دستور: sources:segment (2 بار)']
            && ($d->sections['نمایه‌ی جست‌وجو'] ?? null) === ['خطای نمایه‌ی جست‌وجو']);
    }

    public function test_a_telegram_chat_links_through_the_start_token_and_receives_its_channel(): void
    {
        Http::fake();
        config(['notifications.telegram' => ['bot_token' => 't', 'bot_username' => 'FaharesBot', 'webhook_secret' => 'sec', 'reviewers_chat_id' => null, 'alerts_chat_id' => null]]);
        $user = $this->user();

        $redirect = $this->actingAs($user)->post(route('telegram.link'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://t.me/FaharesBot?start=', $redirect);
        $token = substr($redirect, strpos($redirect, '=') + 1);

        $update = ['message' => ['text' => "/start {$token}", 'chat' => ['id' => 4242, 'type' => 'private']]];
        $this->postJson('/telegram/webhook', $update)->assertForbidden();
        $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();
        $this->assertSame('4242', $user->fresh()->telegram_chat_id);

        NotificationPreferences::set($user, 'suggestion.reviewed', 'telegram', true);
        $this->assertContains(\App\Notifications\Channels\TelegramChannel::class, NotificationPreferences::channelsFor($user->fresh(), 'suggestion.reviewed'));

        // The token works once; /stop unlinks
        $this->postJson('/telegram/webhook', ['message' => ['text' => '/stop', 'chat' => ['id' => 4242, 'type' => 'private']]], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();
        $this->assertNull($user->fresh()->telegram_chat_id);
        $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();
        $this->assertNull($user->fresh()->telegram_chat_id);
    }
}
