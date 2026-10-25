<?php

namespace Tests\Feature\Notifications;

use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\User;
use App\Models\Work;
use App\Notifications\NotificationPreferences;
use App\Notifications\SuggestionReviewed;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/test-endpoint';

    private function user(string $role = 'user'): User
    {
        return User::create(['name' => 'کاربر مرورگر', 'email' => 'push-' . uniqid() . '@example.com', 'password' => 'password123', 'role' => $role]);
    }

    private function subscribe(User $user, string $endpoint = self::ENDPOINT)
    {
        return $this->actingAs($user)->postJson('/push-subscriptions', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'authToken'], 'contentEncoding' => 'aes128gcm']);
    }

    public function test_the_browser_channel_is_off_until_a_browser_subscribes(): void
    {
        $user = $this->user();
        $this->assertNotContains(WebPushChannel::class, NotificationPreferences::channelsFor($user, SuggestionReviewed::TYPE));

        // A stored choice alone sends nothing while no browser is subscribed
        NotificationPreferences::set($user, SuggestionReviewed::TYPE, 'webpush', true);
        $this->assertNotContains(WebPushChannel::class, NotificationPreferences::channelsFor($user, SuggestionReviewed::TYPE));
    }

    public function test_subscribing_saves_the_browser_and_turns_the_channel_on_for_its_types_only(): void
    {
        $user = $this->user();
        $this->subscribe($user)->assertOk();

        $this->assertSame(1, $user->pushSubscriptions()->count());
        $this->assertContains(WebPushChannel::class, NotificationPreferences::channelsFor($user, SuggestionReviewed::TYPE));
        // Only the two types with a browser column; a researcher does not receive new suggestions at all
        $this->assertFalse(NotificationPreferences::enabled($user, 'suggestion.submitted', 'webpush'));

        // A reviewer who switched the channel off for new suggestions keeps that choice on the next browser
        $admin = $this->user('admin');
        NotificationPreferences::set($admin, 'suggestion.submitted', 'webpush', false);
        $this->subscribe($admin, self::ENDPOINT . '-2')->assertOk();
        $this->assertFalse(NotificationPreferences::enabled($admin, 'suggestion.submitted', 'webpush'));
        $this->assertTrue(NotificationPreferences::enabled($admin, 'suggestion.reviewed', 'webpush'));
        $this->assertFalse(NotificationPreferences::enabled($admin, 'user.registered', 'webpush'));
    }

    public function test_the_same_browser_moves_to_whoever_signs_in_and_can_be_removed(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->subscribe($first)->assertOk();
        $this->subscribe($second)->assertOk();
        $this->assertSame(0, $first->pushSubscriptions()->count());
        $this->assertSame(1, $second->pushSubscriptions()->count());

        $this->actingAs($second)->deleteJson('/push-subscriptions', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_guests_and_bad_endpoints_are_refused(): void
    {
        $this->postJson('/push-subscriptions', ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => 'k', 'auth' => 'a']])->assertUnauthorized();
        $this->actingAs($this->user())->postJson('/push-subscriptions', ['endpoint' => 'http://insecure.example/x', 'keys' => ['p256dh' => 'k', 'auth' => 'a']])->assertUnprocessable();
    }

    public function test_a_subscription_the_push_service_rejects_with_410_or_404_is_removed(): void
    {
        $user = $this->user();
        $this->subscribe($user);
        $this->subscribe($user, self::ENDPOINT . '-ok');
        $handler = app(ReportHandler::class);
        $message = new WebPushMessage;

        foreach ([410, 404] as $status) {
            $subscription = $user->pushSubscriptions()->where('endpoint', self::ENDPOINT)->first() ?? $user->updatePushSubscription(self::ENDPOINT, 'k', 'a');
            $report = new MessageSentReport(new Request('POST', self::ENDPOINT), new Response($status), false, 'Gone');
            $handler->handleReport($report, $subscription, $message);
            $this->assertFalse(PushSubscription::where('endpoint', self::ENDPOINT)->exists(), (string) $status);
        }
        $ok = new MessageSentReport(new Request('POST', self::ENDPOINT . '-ok'), new Response(201));
        $handler->handleReport($ok, $user->pushSubscriptions()->sole(), $message);
        $this->assertSame(1, $user->pushSubscriptions()->count());
    }

    public function test_the_push_carries_the_title_the_record_and_the_link_only(): void
    {
        $user = $this->user();
        $work = Work::create(['primary_title' => 'اثر آزمون مرورگر', 'clean_title' => 'اثر آزمون مرورگر', 'slug' => 'push-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $m = Manuscript::create(['work_id' => $work->id, 'shelfmark' => '۱', 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        $s = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place', 'suggested_value' => 'هرات', 'status' => 'rejected', 'public_note' => 'یادداشت داور', 'user_id' => $user->id]);

        $push = (new SuggestionReviewed($s))->toWebPush($user)->toArray();
        $this->assertNotEmpty($push['title']);
        $this->assertStringNotContainsString('یادداشت داور', $push['body']);
        $this->assertStringStartsWith('http', $push['data']['url']);
    }

    public function test_the_settings_page_offers_the_switch_only_with_vapid_keys(): void
    {
        $user = $this->user();
        config(['webpush.vapid.public_key' => null]);
        $this->actingAs($user)->get(route('notifications.settings'))->assertOk()->assertDontSee('vapid-public-key', false)->assertSee('مرورگر');

        config(['webpush.vapid.public_key' => 'BTestPublicKey']);
        $this->actingAs($user)->get(route('notifications.settings'))->assertOk()
            ->assertSee('name="vapid-public-key" content="BTestPublicKey"', false)->assertSee('webPush(', false)->assertSee('/manifest.json', false);
    }
}
