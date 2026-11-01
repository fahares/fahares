<?php

namespace Tests\Feature;

use App\Models\FieldSuggestion;
use App\Models\User;
use App\Notifications\SuggestionSubmitted;
use App\Support\Insights;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InsightsStatsRequestTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_insights_page_ends_with_an_open_request_form()
    {
        Insights::refresh();

        $this->get(route('insights'))->assertOk()
            ->assertSee('چه آماری برایتان سودمند است؟')
            ->assertSee('id="request-stats"', false)
            ->assertSee('برای پاسخ به چه پرسشی؟')
            ->assertSee('نمونه یا توضیح')
            ->assertSee('کاتبان پرکار هر شهر')
            ->assertSee('پیشنهاد درباره‌ی این نمودار')
            ->assertSee("body.append('category', 'stats')", false);
    }

    public function test_a_guest_request_is_stored_as_stats_feedback_and_reaches_the_review_queue()
    {
        Notification::fake();
        $admin = User::firstOrCreate(['email' => 'test-admin@fahares.net'], ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']);
        $admin->forceFill(['role' => 'admin', 'email_verified_at' => $admin->email_verified_at ?? now()])->save();

        $this->postJson(route('feedback.store'), [
            'category' => 'stats', 'message' => 'سهم نسخه‌های مذهّب در هر سده',
            'question' => 'آیا تذهیب در دوره‌ی صفوی رایج‌تر شد؟', 'example' => 'نموداری مانند خط زمانی کتابت',
            'section' => 'خط زمانی کتابت', 'page_url' => '/insights?century=11&script=نستعلیق', 'page_title' => 'آمار',
        ])->assertOk();

        $request = FieldSuggestion::where('kind', 'feedback')->where('field_name', 'stats')->latest('id')->first();
        $this->assertNull($request->suggestable_type);
        $this->assertSame('pending', $request->status);
        $this->assertSame('درخواست آمار یا نمودار تازه', $request->field_label);
        $this->assertSame('/insights?century=11&script=نستعلیق', $request->payload['url']);
        $this->assertSame('خط زمانی کتابت', $request->payload['section']);
        $this->assertSame('آیا تذهیب در دوره‌ی صفوی رایج‌تر شد؟', $request->payload['question']);
        Notification::assertSentTo($admin, SuggestionSubmitted::class);

        $this->actingAs($admin)->get('/admin/suggestions?type=feedback')->assertOk()->assertSee('درخواست آمار یا نمودار تازه');
        $this->get("/admin/suggestions/{$request->id}")->assertOk()
            ->assertSee('بازخورد سایت: درخواست آمار یا نمودار تازه')
            ->assertSee('آیا تذهیب در دوره‌ی صفوی رایج‌تر شد؟')
            ->assertSee('نموداری مانند خط زمانی کتابت')
            ->assertSee('درباره‌ی نمودار:');
    }

    public function test_a_request_needs_its_text_and_the_honeypot_stays_empty()
    {
        $this->postJson(route('feedback.store'), ['category' => 'stats', 'message' => '', 'question' => 'چرا؟'])
            ->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postJson(route('feedback.store'), ['category' => 'stats', 'message' => 'شمار نسخه‌های هر کتابخانه', 'website' => 'spam'])
            ->assertStatus(422)->assertJsonValidationErrors('website');
        $this->assertSame(0, FieldSuggestion::where('field_name', 'stats')->where('payload->question', 'چرا؟')->count());
    }
}
