<?php

namespace Tests\Feature;

use App\Models\FieldSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The «تماس با ما» page and its form (backlog item 173, step 14.431). */
class ContactPageTest extends TestCase
{
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'پژوهشگر تماس',
            'email' => 'contact-' . uniqid() . '@example.com',
            'subject' => 'همکاری',
            'message' => 'سلام؛ دربارۀ فهرست کتابخانه‌ای پرسشی دارم.',
            'started' => encrypt(now()->timestamp),
        ];
    }

    public function test_the_page_shows_every_channel_and_is_linked_from_the_footer()
    {
        $this->get('/contact')->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('تماس با ما')
            ->assertSee('mailto:info@fahares.net', false)
            ->assertSee('tel:+989192535021', false)
            ->assertSee('tel:+9647840001820', false)
            ->assertSee('https://wa.me/989192535021', false)
            ->assertSee('https://wa.me/9647840001820', false)
            ->assertSee('https://t.me/+989192535021', false)
            ->assertSee('https://t.me/+9647840001820', false)
            ->assertSee('https://eitaa.com/tabib2m', false)
            ->assertSee('name="website"', false)
            ->assertSee(route('contact.store'), false);

        // Only the Iranian number has Eitaa
        $this->assertSame(1, substr_count($this->get('/contact')->getContent(), 'eitaa.com/'));

        // The footer link (in the layout of every page)
        $this->get('/contact')->assertSee('<li><a href="' . route('contact') . '"', false);
    }

    public function test_a_guest_message_reaches_the_feedback_queue_and_asks_for_an_answer_by_email()
    {
        Notification::fake();
        $form = $this->form();
        $this->travel(10)->seconds();

        $this->post('/contact', $form)->assertRedirect(route('contact'))->assertSessionHas('contact_sent');

        $message = FieldSuggestion::where('kind', 'feedback')->where('field_name', 'contact')->latest('id')->firstOrFail();
        $this->assertSame("موضوع: همکاری\n\nسلام؛ دربارۀ فهرست کتابخانه‌ای پرسشی دارم.", $message->suggested_value);
        $this->assertSame('پژوهشگر تماس', $message->guest_name);
        $this->assertSame($form['email'], $message->guest_email);
        $this->assertTrue($message->notify_submitter);
        $this->assertSame('pending', $message->status);
        $this->assertSame('/contact', $message->payload['url']);
        $this->assertSame('پیام از صفحۀ تماس با ما', $message->field_label);
    }

    public function test_spam_is_refused()
    {
        Notification::fake();
        $before = FieldSuggestion::where('field_name', 'contact')->count();

        // The honeypot
        $this->travel(10)->seconds();
        $this->post('/contact', ['website' => 'http://spam.example'] + $this->form(['started' => encrypt(now()->subSeconds(10)->timestamp)]))
            ->assertSessionHasErrors('website');
        // Sent at once after the page was rendered
        $this->post('/contact', $this->form(['started' => encrypt(now()->timestamp)]))->assertSessionHasErrors('form');
        // A forged time
        $this->post('/contact', $this->form(['started' => (string) now()->subMinute()->timestamp]))->assertSessionHasErrors('form');
        // A guest must leave an email to be answered
        $this->post('/contact', $this->form(['email' => '', 'started' => encrypt(now()->subMinute()->timestamp)]))->assertSessionHasErrors('email');

        $this->assertSame($before, FieldSuggestion::where('field_name', 'contact')->count());
    }

    public function test_the_form_is_rate_limited()
    {
        Notification::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/contact', $this->form(['started' => encrypt(now()->subMinute()->timestamp)]))->assertRedirect();
        }
        $this->post('/contact', $this->form(['started' => encrypt(now()->subMinute()->timestamp)]))->assertStatus(429);
    }

    public function test_a_signed_in_user_sends_under_their_own_name_and_the_feedback_dialog_does_not_offer_the_category()
    {
        Notification::fake();
        $user = User::create(['name' => 'کاربر تماس', 'email' => 'contact-user-' . uniqid() . '@example.com', 'password' => 'password123', 'role' => 'user']);

        $this->actingAs($user)->get('/contact')->assertOk()->assertDontSee('name="email"', false);
        $this->actingAs($user)->post('/contact', ['message' => 'پیامی از کاربر واردشده برای گروه.', 'started' => encrypt(now()->subMinute()->timestamp)])
            ->assertRedirect(route('contact'));

        $message = FieldSuggestion::where('field_name', 'contact')->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($user->email, $message->guest_email);
        $this->assertFalse($message->notify_submitter);

        // «contact» belongs to this page only
        $this->postJson('/feedback', ['category' => 'contact', 'message' => 'از راه پنجرۀ بازخورد'])->assertStatus(422);
        $this->get('/contact')->assertSee('value="bug"', false)->assertDontSee('value="contact"', false);
    }
}
