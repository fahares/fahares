<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\AnnouncementTelegram;
use Tests\TestCase;

/** Step 14.321: the site's Telegram channel is linked from the footer and the Jarideh page. */
class TelegramChannelLinkTest extends TestCase
{
    public function test_footer_and_jarideh_link_the_channel_of_the_setting()
    {
        SiteSetting::put('telegram.channel', '@fahares_net');

        $this->get(route('posts.index'))->assertOk()
            ->assertSee('href="https://t.me/fahares_net"', false)
            ->assertSee('کانال تلگرام فهارس');

        $this->get(route('about'))->assertOk()
            ->assertSee('href="https://t.me/fahares_net"', false)
            ->assertSee('کانال تلگرام');
    }

    public function test_no_link_when_the_channel_is_unset_or_a_numeric_chat_id()
    {
        foreach (['', '-1001234567890'] as $channel) {
            SiteSetting::put('telegram.channel', $channel);
            $this->assertNull(AnnouncementTelegram::channelUrl());
            $this->get(route('posts.index'))->assertOk()->assertDontSee('t.me/', false);
        }
    }

    public function test_the_link_takes_the_name_with_or_without_at()
    {
        SiteSetting::put('telegram.channel', 'fahares_net');
        $this->assertSame('https://t.me/fahares_net', AnnouncementTelegram::channelUrl());
    }
}
