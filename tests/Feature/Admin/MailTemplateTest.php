<?php

namespace Tests\Feature\Admin;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MailTemplateTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('site_settings');

        $this->admin = User::firstOrCreate(
            ['email' => 'test-admin@fahares.net'],
            ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']
        );
        $this->admin->role = 'admin';
        $this->admin->save();
    }

    protected function tearDown(): void
    {
        Cache::forget('site_settings');
        parent::tearDown();
    }

    public function test_emails_carry_the_logo_and_persian_frame_not_the_app_name(): void
    {
        config(['app.name' => 'Fahares']);
        $html = (string) (new ResetPassword('token'))->toMail($this->admin)->render();

        $this->assertStringContainsString('images/brand/shamseh-180.png', $html);
        $this->assertStringContainsString(SiteSetting::DEFAULTS['mail.subtitle'], $html);
        $this->assertStringContainsString(SiteSetting::DEFAULTS['mail.footer'], $html);
        $this->assertStringNotContainsString('>Fahares<', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    public function test_admin_edits_the_frame_and_the_preview_follows(): void
    {
        $this->actingAs($this->admin)->get(route('admin.mail.index'))->assertOk()->assertSee('امضای پایان ایمیل');

        $this->actingAs($this->admin)->put(route('admin.mail.update'), [
            'mail__subtitle' => 'زیرعنوان آزمایشی',
            'mail__salutation' => 'با سپاس، فهارس',
            'mail__footer' => '',
        ])->assertRedirect();

        $this->assertSame('0', SiteSetting::get('mail.show_logo'));
        $this->assertSame(SiteSetting::DEFAULTS['mail.footer'], SiteSetting::get('mail.footer'));

        $html = $this->actingAs($this->admin)->get(route('admin.mail.preview'))->assertOk()->getContent();
        $this->assertStringContainsString('زیرعنوان آزمایشی', $html);
        $this->assertStringContainsString('با سپاس، فهارس', $html);
        $this->assertStringNotContainsString('brand/shamseh-180.png', $html);
    }

    public function test_home_texts_form_leaves_mail_settings_alone(): void
    {
        SiteSetting::put('mail.subtitle', 'دست‌نخورده');
        $this->actingAs($this->admin)->put(route('admin.home.settings'), ['home__headline' => 'عنوان'])->assertRedirect();

        $this->assertSame('دست‌نخورده', SiteSetting::get('mail.subtitle'));
    }

    public function test_guests_cannot_reach_the_page(): void
    {
        $this->get(route('admin.mail.index'))->assertRedirect();
    }
}
