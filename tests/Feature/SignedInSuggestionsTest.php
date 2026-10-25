<?php

namespace Tests\Feature;

use App\Auth\Permissions;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\User;
use App\Models\Work;
use App\Support\Phone;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Suggestions from signed-in users only, the phone number and the Persian name at sign-up (backlog items 74–76). */
class SignedInSuggestionsTest extends TestCase
{
    protected function manuscript(): Manuscript
    {
        $work = Work::create(['primary_title' => 'اثر آزمون ورود', 'clean_title' => 'اثر آزمون ورود', 'slug' => 'test-signin-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create(['work_id' => $work->id, 'shelfmark' => '۱۲۳', 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
    }

    protected function user(array $attributes = []): User
    {
        $verified = $attributes['verified'] ?? true;
        unset($attributes['verified']);
        $user = User::create($attributes + ['name' => 'پژوهشگر ورود', 'email' => 'signin-' . uniqid() . '@example.com', 'password' => 'password123', 'role' => 'user']);
        if ($verified) {
            $user->markEmailAsVerified();
        }

        return $user;
    }

    protected function suggestion(Manuscript $m): array
    {
        return ['type' => 'manuscript', 'id' => $m->id, 'kind' => 'general', 'suggested_value' => 'نکته‌ای درباره‌ی این نسخه'];
    }

    public function test_guests_cannot_suggest_but_can_still_send_site_feedback()
    {
        Notification::fake();
        $m = $this->manuscript();

        $this->postJson('/suggestions', $this->suggestion($m))->assertUnauthorized();
        $this->post('/suggestions', $this->suggestion($m))->assertRedirect('/login');
        $this->postJson('/suggestions', ['type' => 'manuscript', 'id' => $m->id, 'kind' => 'note', 'field_name' => 'observation', 'note' => ['text' => 'یادداشت مهمان']])->assertUnauthorized();
        $this->assertSame(0, FieldSuggestion::whereNotNull('suggestable_type')->count());

        // Site feedback stays open to guests, with its honeypot
        $this->postJson('/feedback', ['category' => 'bug', 'message' => 'جستجو کار نمی‌کند'])->assertOk();
        $this->postJson('/feedback', ['category' => 'bug', 'message' => 'هرزنامه‌ی خودکار', 'website' => 'spam'])->assertStatus(422);
        $this->assertSame(1, FieldSuggestion::where('kind', 'feedback')->count());

        // A guest sees the sign-in dialog, and the desk no longer asks for a guest name or email
        $this->get(route('manuscripts.show', $m))->assertOk()
            ->assertSee('برای ثبت اصلاح وارد شوید یا در یک دقیقه ثبت‌نام کنید')
            ->assertDontSee('پیشنهادها به نام شما ثبت می‌شوند')
            ->assertDontSee('نتیجه‌ی داوری را به این ایمیل خبر دهید');
    }

    public function test_a_signed_in_user_suggests_under_their_account()
    {
        Notification::fake();
        $m = $this->manuscript();
        $user = $this->user();

        // Guest fields sent by an old page are ignored
        $this->actingAs($user)->postJson('/suggestions', $this->suggestion($m) + ['guest_name' => 'دیگری', 'guest_email' => 'x@example.com', 'notify_submitter' => 1])->assertOk();
        $s = FieldSuggestion::where('suggestable_id', $m->id)->sole();
        $this->assertSame([$user->id, $user->name, $user->email, false], [$s->user_id, $s->guest_name, $s->guest_email, $s->notify_submitter]);
    }

    public function test_the_name_waits_for_a_verified_email()
    {
        Notification::fake();
        $m = $this->manuscript();
        $user = $this->user(['verified' => false]);

        $this->actingAs($user)->postJson('/suggestions', $this->suggestion($m) + ['credit_name' => 'دکتر ورود'])->assertOk();
        $s = FieldSuggestion::where('suggestable_id', $m->id)->sole();
        $this->assertNull($s->credit_name);
        $this->assertSame('دکتر ورود', $s->held_credit_name);
        $this->get('/my/suggestions')->assertSee('پس از')->assertSee('تأیید ایمیل');

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect('/my/suggestions')->assertSessionHas('success', fn ($msg) => str_contains($msg, 'نام شما کنار پیشنهادهایتان منتشر می‌شود'));
        $this->assertSame(['دکتر ورود', null], [$s->fresh()->credit_name, $s->fresh()->held_credit_name]);

        // Verified, a new name is published at once
        $this->post("/my/suggestions/{$s->id}/credit", ['credit_name' => 'نام تازه'])->assertSessionHas('success');
        $this->assertSame('نام تازه', $s->fresh()->credit_name);
    }

    public function test_phone_numbers_are_normalized_to_e164()
    {
        $cases = [
            '09121234567' => '+989121234567',
            '۰۹۱۲ ۱۲۳ ۴۵۶۷' => '+989121234567',
            '٠٩١٢-١٢٣-٤٥٦٧' => '+989121234567',
            '9121234567' => '+989121234567',
            '989121234567' => '+989121234567',
            '+98 912 123 4567' => '+989121234567',
            '+98 0912 123 4567' => '+989121234567',
            '0098 912 123 4567' => '+989121234567',
            '021 6640 1234' => '+982166401234',
            '+44 20 7946 0958' => '+442079460958',
            '0049 30 1234567' => '+49301234567',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Phone::normalize($input), $input);
        }
        foreach (['', 'abc', '0912', '12345678', '+98 912 123', '+0 123 4567', '+1234567890123456'] as $bad) {
            $this->assertNull(Phone::normalize($bad), $bad);
        }
    }

    public function test_sign_up_takes_an_optional_phone_and_returns_to_the_suggestion_form()
    {
        Notification::fake();
        $intended = '/manuscripts/1?suggest=field.copy_place';

        // The dialog's short form: no repeated password, a JSON answer with the address to follow
        $this->postJson('/register', ['name' => 'علی آزمون', 'email' => 'dialog@example.com', 'password' => 'password123', 'phone' => '۰۹۱۲۱۲۳۴۵۶۷', 'intended' => $intended])
            ->assertOk()->assertJson(['redirect' => url($intended)]);
        $this->assertSame('+989121234567', User::where('email', 'dialog@example.com')->value('phone'));
        $this->assertAuthenticated();

        // The full form still checks the repeated password; a bad phone is refused; another site is never a target
        $this->post('/logout');
        $this->post('/register', ['name' => 'آزمون', 'email' => 'full@example.com', 'password' => 'password123', 'password_confirmation' => 'other-pass', 'phone' => '0912'])
            ->assertSessionHasErrors(['password', 'phone']);
        $this->postJson('/register', ['name' => 'آزمون', 'email' => 'far@example.com', 'password' => 'password123', 'intended' => '//evil.example/x'])
            ->assertOk()->assertJson(['redirect' => route('home')]);
        $this->assertNull(User::where('email', 'far@example.com')->value('phone'));
    }

    public function test_sign_in_pages_remember_where_to_return()
    {
        $this->get('/login?intended=' . urlencode('/works/5?suggest=general'))->assertOk()->assertSessionHas('url.intended', url('/works/5?suggest=general'));
        $user = $this->user();
        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])->assertRedirect(url('/works/5?suggest=general'));
    }

    public function test_the_registration_and_account_pages_ask_for_a_persian_name_and_an_optional_phone()
    {
        $hint = 'لطفاً نام و نام خانوادگی را به فارسی بنویسید';
        $note = 'فقط برای هماهنگی همکاری علمی؛ نمایش داده نمی‌شود.';
        $this->get('/register')->assertOk()->assertSee($hint)->assertSee($note)->assertSee('name="phone"', false);

        $user = $this->user();
        $this->actingAs($user)->get('/settings/profile')->assertOk()->assertSee($hint)->assertSee($note);
        $this->post('/settings/profile', ['name' => 'نام فارسی تازه', 'affiliation' => 'دانشگاه تهران', 'phone' => '+44 20 7946 0958'])->assertSessionHas('success');
        $this->assertSame(['نام فارسی تازه', 'دانشگاه تهران', '+442079460958'], [$user->fresh()->name, $user->fresh()->affiliation, $user->fresh()->phone]);
        $this->post('/settings/profile', ['name' => 'نام فارسی تازه', 'phone' => ''])->assertSessionHas('success');
        $this->assertNull($user->fresh()->phone);
    }

    public function test_the_phone_is_shown_only_in_the_panel_with_its_permission()
    {
        $user = $this->user(['phone' => '+989121234567', 'name' => 'پژوهشگر تلفن‌دار']);
        $m = $this->manuscript();
        FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'general', 'suggested_value' => 'نکته', 'status' => 'approved',
            'reviewed_at' => now(), 'user_id' => $user->id, 'guest_name' => $user->name, 'guest_email' => $user->email, 'credit_name' => $user->name]);

        // Never in a serialized user, nor on public pages
        $this->assertArrayNotHasKey('phone', $user->toArray());
        foreach (['/contributions', "/contributors/{$user->id}", route('manuscripts.show', $m)] as $page) {
            $this->get($page)->assertDontSee('9121234567');
        }

        $admin = $this->user(['role' => 'admin', 'name' => 'مدیر']);
        $this->actingAs($admin)->get("/admin/users/{$user->id}/edit")->assertOk()->assertSee('+989121234567');

        // An editor allowed to manage users still needs users.contact to see the number
        $editor = $this->user(['role' => 'editor', 'name' => 'داور']);
        $grants = Permissions::matrix();
        $grants['editor'][] = 'users.manage';
        Permissions::save($grants);
        $this->actingAs($editor)->get("/admin/users/{$user->id}/edit")->assertOk()->assertDontSee('9121234567');
        $grants['editor'][] = 'users.contact';
        Permissions::save($grants);
        $this->get("/admin/users/{$user->id}/edit")->assertOk()->assertSee('+989121234567');
    }

    protected function fakeGoogle(string $id, string $email, string $name): void
    {
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'x']);
        $google = (new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])->map(['id' => $id, 'name' => $name, 'email' => $email]);
        $provider = \Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->andReturn($google);
        $factory = \Mockery::mock(\Laravel\Socialite\Contracts\Factory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);
        \Laravel\Socialite\Facades\Socialite::swap($factory);
    }

    public function test_a_first_google_sign_in_with_a_latin_name_offers_to_complete_the_account_once()
    {
        $this->fakeGoogle('g-latin', 'latin@example.com', 'Ali Rezaei');
        session(['url.intended' => url('/works/3?suggest=note')]);
        $this->get('/auth/google/callback')->assertRedirect(route('account.complete'));
        $this->get(route('account.complete'))->assertOk()->assertSee('تکمیل حساب')->assertSee('Ali Rezaei');

        // Saved: the Persian name and the phone, then back where the user was going
        $this->post(route('account.complete.save'), ['name' => 'علی رضایی', 'phone' => '۰۹۱۲۱۲۳۴۵۶۷'])->assertRedirect(url('/works/3?suggest=note'));
        $user = User::where('email', 'latin@example.com')->sole();
        $this->assertSame(['علی رضایی', '+989121234567'], [$user->name, $user->phone]);

        // A later sign-in does not ask again; a Persian Google name or a skip never changes the account
        $this->post('/logout');
        $this->fakeGoogle('g-latin', 'latin@example.com', 'Ali Rezaei');
        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $this->post('/logout');
        $this->fakeGoogle('g-fa', 'farsi@example.com', 'مریم احمدی');
        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $this->post('/logout');
        $this->fakeGoogle('g-skip', 'skip@example.com', 'John Smith');
        $this->get('/auth/google/callback')->assertRedirect(route('account.complete'));
        $this->post(route('account.complete.save'), ['skip' => 1, 'name' => ''])->assertRedirect(route('home'));
        $this->assertSame('John Smith', User::where('email', 'skip@example.com')->value('name'));
    }
}
