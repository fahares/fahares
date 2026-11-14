<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    protected User $admin;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::create(['name' => 'Admin Tester', 'email' => 'test-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $this->member = User::create(['name' => 'Member Tester', 'email' => 'test-member@fahares.net', 'password' => 'password123', 'role' => 'user']);
    }

    private function running(array $attributes = []): Announcement
    {
        return Announcement::create($attributes + [
            'title' => 'کانال تلگرام آزمایشی راه افتاد', 'body' => 'خبرهای تازه را آنجا بخوانید.',
            'link_url' => 'https://t.me/example', 'link_label' => 'پیوستن',
            'style' => 'card', 'audience' => 'all', 'days' => 14, 'is_active' => true,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(14),
        ]);
    }

    public function test_admin_writes_a_notice_and_switches_it_on()
    {
        $this->actingAs($this->admin)->get(route('admin.announcements.create'))->assertOk();

        $this->post(route('admin.announcements.store'), [
            'title' => 'فهرست کتابخانه‌ی آزمایشی افزوده شد', 'body' => 'هزار نسخه‌ی تازه.',
            'link_url' => '/libraries', 'style' => 'card', 'audience' => 'all', 'days' => 10,
            'image' => UploadedFile::fake()->createWithContent('a.jpg', base64_decode(
                '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='
            )),
        ])->assertRedirect();

        $notice = Announcement::where('title', 'فهرست کتابخانه‌ی آزمایشی افزوده شد')->firstOrFail();
        $this->assertSame('off', $notice->status());
        $this->assertNull($notice->starts_at);
        Storage::disk('public')->assertExists($notice->image_path);
        $this->assertNull(Announcement::forViewer(null));

        // Switched on: the run begins now and lasts the given days
        $this->put(route('admin.announcements.update', $notice), [
            'title' => $notice->title, 'link_url' => '/libraries', 'style' => 'card', 'audience' => 'all', 'days' => 10, 'is_active' => 1,
        ])->assertRedirect();
        $notice->refresh();
        $this->assertSame('running', $notice->status());
        $this->assertTrue($notice->starts_at->copy()->addDays(10)->equalTo($notice->ends_at));
        $this->assertSame($notice->id, Announcement::forViewer(null)['id']);

        // Saving a running notice keeps its start; a longer run moves only the end
        $start = $notice->starts_at;
        $this->travel(2)->days();
        $this->put(route('admin.announcements.update', $notice), [
            'title' => $notice->title, 'style' => 'modal', 'audience' => 'all', 'days' => 20, 'is_active' => 1,
        ]);
        $notice->refresh();
        $this->assertTrue($start->equalTo($notice->starts_at));
        $this->assertTrue($start->copy()->addDays(20)->equalTo($notice->ends_at));

        $this->get(route('admin.announcements.index'))->assertOk()->assertSee($notice->title);

        $this->delete(route('admin.announcements.destroy', $notice))->assertRedirect(route('admin.announcements.index'));
        $this->assertDatabaseMissing('announcements', ['id' => $notice->id]);
        Storage::disk('public')->assertMissing($notice->image_path);
    }

    public function test_link_must_be_a_site_path_or_https()
    {
        $base = ['title' => 'اعلان', 'style' => 'card', 'audience' => 'all', 'days' => 5];
        foreach (['javascript:alert(1)', '//evil.example', 'http://plain.example', 'posts/1'] as $bad) {
            $this->actingAs($this->admin)->post(route('admin.announcements.store'), $base + ['link_url' => $bad])->assertSessionHasErrors('link_url');
        }
        $this->assertSame(0, Announcement::count());
    }

    public function test_only_a_manager_reaches_the_admin_pages()
    {
        $this->actingAs($this->member)->get(route('admin.announcements.index'))->assertForbidden();
    }

    public function test_page_offers_the_newest_running_notice_of_the_visitors_audience()
    {
        $old = $this->running(['title' => 'اعلان کهنه‌تر', 'starts_at' => now()->subDays(3)]);
        $forMembers = $this->running(['title' => 'اعلان اعضا', 'audience' => 'members']);
        $this->running(['title' => 'اعلان خاموش', 'is_active' => false, 'starts_at' => now()]);
        $this->running(['title' => 'اعلان پایان‌یافته', 'starts_at' => now()->subDays(20), 'ends_at' => now()->subDay()]);

        $this->assertSame($old->id, Announcement::forViewer(null)['id']);
        $this->assertSame($forMembers->id, Announcement::forViewer($this->member)['id']);

        $this->get(route('home'))->assertOk()->assertSee('data-announcement', false)->assertSee('اعلان کهنه‌تر')->assertDontSee('اعلان اعضا');
        // Nothing interrupts signing in
        $this->get(route('login'))->assertOk()->assertDontSee('data-announcement', false);

        // The run ends by itself, though the cached list still holds the notice
        $this->travel(15)->days();
        $this->assertNull(Announcement::forViewer(null));
        $this->get(route('home'))->assertOk()->assertDontSee('data-announcement', false);
    }

    public function test_seen_and_click_are_counted_and_a_member_is_not_shown_it_again()
    {
        $notice = $this->running();

        $this->postJson(route('announcements.event', $notice), ['event' => 'view'])->assertNoContent();
        $this->postJson(route('announcements.event', $notice), ['event' => 'click'])->assertNoContent();
        $this->postJson(route('announcements.event', $notice), ['event' => 'other'])->assertStatus(422);
        $this->assertSame([1, 1], [$notice->fresh()->views_count, $notice->fresh()->clicks_count]);
        $this->assertSame(0, DB::table('announcement_user')->count());

        $this->assertSame($notice->id, Announcement::forViewer($this->member)['id']);
        $this->actingAs($this->member)->postJson(route('announcements.event', $notice), ['event' => 'view'])->assertNoContent();
        $this->assertNull(Announcement::forViewer($this->member));
        $this->get(route('home'))->assertOk()->assertDontSee('data-announcement', false);

        // Another member, and a guest, still get it
        $this->assertSame($notice->id, Announcement::forViewer($this->admin)['id']);
        $this->assertSame($notice->id, Announcement::forViewer(null)['id']);

        // A notice that is no longer running is not counted
        $notice->update(['is_active' => false]);
        $this->postJson(route('announcements.event', $notice), ['event' => 'view'])->assertNoContent();
        $this->assertSame(2, $notice->fresh()->views_count);
    }

    private function fakeTelegram(): void
    {
        config(['notifications.telegram.bot_token' => 'TEST-TOKEN']);
        \Illuminate\Support\Facades\Http::fake(['api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
    }

    private function telegramCalls(string $method): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\Http::recorded(fn ($request) => str_ends_with($request->url(), "/botTEST-TOKEN/{$method}"))->map(fn ($pair) => $pair[0])->values();
    }

    public function test_notice_goes_to_the_telegram_channel_once_when_first_switched_on()
    {
        $this->fakeTelegram();
        $form = ['title' => 'فهرست <تازه> افزوده شد', 'body' => 'هزار نسخه.', 'link_url' => '/libraries', 'style' => 'card', 'audience' => 'all', 'days' => 10, 'telegram' => 1];

        // Saved off: nothing leaves the site
        $this->actingAs($this->admin)->post(route('admin.announcements.store'), $form)->assertRedirect();
        $notice = Announcement::firstOrFail();
        $this->assertTrue($notice->telegram);
        \Illuminate\Support\Facades\Http::assertNothingSent();

        $this->put(route('admin.announcements.update', $notice), $form + ['is_active' => 1])->assertSessionHas('success');
        $sent = $this->telegramCalls('sendMessage');
        $this->assertCount(1, $sent);
        $this->assertSame('@fahares_net', $sent[0]['chat_id']);
        $this->assertSame(
            "<b>فهرست &lt;تازه&gt; افزوده شد</b>\n\nهزار نسخه.\n\n🔗 <a href=\"" . url('/libraries') . "\">بیشتر بخوانید</a>\n\n🌐 fahares.net | 📣 @fahares_net",
            $sent[0]['text'],
        );
        $this->assertSame(['url' => url('/libraries')], $sent[0]['link_preview_options']);
        $notice->refresh();
        $this->assertSame(77, $notice->telegram_message_id);
        $this->assertNotNull($notice->telegram_sent_at);

        // Saved again unchanged, or switched off and on: no second post
        $this->put(route('admin.announcements.update', $notice), $form + ['is_active' => 1]);
        $this->put(route('admin.announcements.update', $notice), $form);
        $this->put(route('admin.announcements.update', $notice), $form + ['is_active' => 1]);
        $this->assertCount(1, $this->telegramCalls('sendMessage'));
        $this->assertCount(0, $this->telegramCalls('editMessageText'));

        // Its own Telegram text replaces the title and body there, and edits the same post
        $this->put(route('admin.announcements.update', $notice), ['telegram_text' => '📚 فهرست تازه رسید!', 'is_active' => 1] + $form);
        $edits = $this->telegramCalls('editMessageText');
        $this->assertCount(1, $edits);
        $this->assertSame(77, $edits[0]['message_id']);
        $this->assertStringStartsWith("📚 فهرست تازه رسید!\n\n🔗 ", $edits[0]['text']);
        $this->assertCount(1, $this->telegramCalls('sendMessage'));

        $this->get(route('admin.announcements.edit', $notice))->assertOk()->assertSee('https://t.me/fahares_net/77');
    }

    public function test_only_a_notice_for_everyone_goes_to_telegram_and_a_picture_goes_as_a_photo()
    {
        $this->fakeTelegram();
        $base = ['title' => 'اعلان اعضا', 'style' => 'card', 'days' => 5, 'telegram' => 1, 'is_active' => 1];

        $this->actingAs($this->admin)->post(route('admin.announcements.store'), $base + ['audience' => 'members'])->assertRedirect();
        $this->assertFalse(Announcement::firstOrFail()->telegram);
        \Illuminate\Support\Facades\Http::assertNothingSent();

        $image = fn () => UploadedFile::fake()->image('a.jpg', 40, 20);
        // A caption holds far less than a message
        $this->post(route('admin.announcements.store'), $base + ['audience' => 'all', 'telegram_text' => str_repeat('م', 1100), 'image' => $image()])
            ->assertSessionHasErrors('telegram_text');
        $this->assertSame(1, Announcement::count());

        $this->post(route('admin.announcements.store'), ['title' => 'اعلان تصویردار', 'audience' => 'all', 'image' => $image()] + $base)->assertRedirect();
        $this->assertCount(1, $this->telegramCalls('sendPhoto'));
        $notice = Announcement::where('title', 'اعلان تصویردار')->firstOrFail();
        $this->assertTrue($notice->telegram_photo);

        $this->put(route('admin.announcements.update', $notice), ['title' => 'اعلان تصویردار، ویراسته', 'audience' => 'all'] + $base);
        $this->assertCount(1, $this->telegramCalls('editMessageCaption'));
    }

    public function test_telegram_failure_keeps_the_notice_and_can_be_sent_again()
    {
        config(['notifications.telegram.bot_token' => 'TEST-TOKEN']);
        \Illuminate\Support\Facades\Http::fakeSequence('api.telegram.org/*')
            ->push(['ok' => false, 'description' => 'Forbidden: bot is not a member of the channel chat'], 403)
            ->push(['ok' => true, 'result' => ['message_id' => 5]]);

        $this->actingAs($this->admin)->post(route('admin.announcements.store'), [
            'title' => 'اعلان', 'style' => 'card', 'audience' => 'all', 'days' => 5, 'telegram' => 1, 'is_active' => 1,
        ])->assertRedirect()->assertSessionHas('error');
        $notice = Announcement::firstOrFail();
        $this->assertTrue($notice->isRunning());
        $this->assertNull($notice->telegram_message_id);
        $this->assertStringContainsString('bot is not a member', $notice->telegram_error);
        $this->assertStringNotContainsString('TEST-TOKEN', $notice->telegram_error);
        $this->get(route('admin.announcements.edit', $notice))->assertOk()->assertSee('دوباره بفرست');

        $this->post(route('admin.announcements.telegram', $notice))->assertSessionHas('success');
        $notice->refresh();
        $this->assertSame(5, $notice->telegram_message_id);
        $this->assertNull($notice->telegram_error);
    }

    public function test_channel_and_footer_are_settings()
    {
        $this->fakeTelegram();
        $this->actingAs($this->member)->put(route('admin.announcements.telegram.settings'), ['channel' => '@other'])->assertForbidden();
        $this->actingAs($this->admin)->put(route('admin.announcements.telegram.settings'), ['channel' => 'not a channel'])->assertSessionHasErrors('channel');
        $this->put(route('admin.announcements.telegram.settings'), ['channel' => 'test_channel', 'footer' => ''])->assertSessionHas('success');
        $this->get(route('admin.announcements.index'))->assertOk()->assertSee('test_channel');

        $this->post(route('admin.announcements.store'), ['title' => 'اعلان', 'style' => 'card', 'audience' => 'all', 'days' => 5, 'telegram' => 1, 'is_active' => 1]);
        $sent = $this->telegramCalls('sendMessage');
        $this->assertSame('@test_channel', $sent[0]['chat_id']);
        $this->assertSame('<b>اعلان</b>', $sent[0]['text']);
        $this->assertSame(['is_disabled' => true], $sent[0]['link_preview_options']);
    }

    public function test_first_run_puts_the_notice_into_members_notifications_once()
    {
        $form = ['title' => 'فهرست تازه افزوده شد', 'body' => "هزار نسخه.\nدر جست‌وجو هست.", 'link_url' => '/libraries', 'style' => 'card', 'audience' => 'all', 'days' => 10];

        $this->actingAs($this->admin)->post(route('admin.announcements.store'), $form)->assertRedirect();
        $notice = Announcement::firstOrFail();
        $this->assertSame(0, $this->member->notifications()->count());

        $this->put(route('admin.announcements.update', $notice), $form + ['is_active' => 1]);
        $this->put(route('admin.announcements.update', $notice), $form);
        $this->put(route('admin.announcements.update', $notice), $form + ['is_active' => 1]);
        $entry = $this->member->notifications()->sole();
        $this->assertSame('site.announcement', $entry->data['type']);
        $this->assertSame('فهرست تازه افزوده شد', $entry->data['title']);
        $this->assertSame(url('/libraries'), $entry->data['url']);
        $this->assertSame($notice->id, $entry->data['announcement_id']);
        $this->assertNotNull($notice->fresh()->notified_at);

        // Only the in-site list is on unless the member chose more
        $this->assertSame(['database'], \App\Notifications\NotificationPreferences::channelsFor($this->member, 'site.announcement'));
        \App\Notifications\NotificationPreferences::set($this->member, 'site.announcement', 'mail', true);
        $this->assertSame(['database', 'mail'], \App\Notifications\NotificationPreferences::channelsFor($this->member, 'site.announcement'));

        // Read in the notifications: the popup does not come up, and nothing is counted as a view
        $this->assertSame($notice->id, Announcement::forViewer($this->member)['id']);
        $this->actingAs($this->member)->get(route('notifications.index'))->assertOk()->assertSee('فهرست تازه افزوده شد');
        $this->assertNull(Announcement::forViewer($this->member));
        $this->assertSame(0, $notice->fresh()->views_count);

        // Seen as the popup: the entry in the notifications is no longer new
        $this->assertSame(1, $this->admin->unreadNotifications()->count());
        $this->actingAs($this->admin)->postJson(route('announcements.event', $notice), ['event' => 'view'])->assertNoContent();
        $this->assertSame(0, $this->admin->unreadNotifications()->count());
    }

    public function test_a_notice_for_guests_or_one_switched_off_is_not_put_into_notifications()
    {
        $base = ['title' => 'اعلان مهمان‌ها', 'style' => 'card', 'days' => 5, 'is_active' => 1];
        $this->actingAs($this->admin)->post(route('admin.announcements.store'), $base + ['audience' => 'guests']);
        $this->assertSame(0, $this->member->notifications()->count());

        $this->post(route('admin.announcements.store'), ['title' => 'اعلان اعضا', 'audience' => 'members'] + $base);
        $this->assertSame('اعلان اعضا', $this->member->notifications()->sole()->data['title']);

        // Switched off before the queue reached it
        $late = $this->running(['title' => 'اعلان خاموش‌شده', 'is_active' => false]);
        (new \App\Jobs\SendAnnouncementNotice($late->id))->handle();
        $this->assertSame(1, $this->member->notifications()->count());
    }

    public function test_manager_previews_a_notice_that_is_off()
    {
        $notice = $this->running(['title' => 'اعلان پیش‌نویس', 'is_active' => false]);
        $url = route('home', ['announcement_preview' => $notice->id]);

        $this->get($url)->assertOk()->assertDontSee('data-announcement', false);
        $this->actingAs($this->member)->get($url)->assertOk()->assertDontSee('data-announcement', false);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('اعلان پیش‌نویس');
    }
}
