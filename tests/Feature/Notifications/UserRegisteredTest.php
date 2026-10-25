<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\UserRegistered;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserRegisteredTest extends TestCase
{
    private function user(string $role): User
    {
        return User::create(['name' => "کاربر {$role}", 'email' => $role . '-' . uniqid() . '@example.com', 'password' => 'password123', 'role' => $role]);
    }

    private function fakeGoogle(string $id, string $email, string $name): void
    {
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'x']);
        $google = (new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])->map(['id' => $id, 'name' => $name, 'email' => $email]);
        $provider = \Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->andReturn($google);
        $factory = \Mockery::mock(\Laravel\Socialite\Contracts\Factory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);
        \Laravel\Socialite\Facades\Socialite::swap($factory);
    }

    public function test_the_sign_up_form_notifies_the_admins_but_not_ordinary_users(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $plain = $this->user('user');

        $this->post('/register', [
            'name' => 'پژوهشگر تازه', 'email' => 'new-' . uniqid() . '@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'affiliation' => 'دانشگاه تهران', 'phone' => '09121234567',
        ])->assertRedirect();

        $new = User::where('name', 'پژوهشگر تازه')->sole();
        Notification::assertSentTo($admin, UserRegistered::class, function (UserRegistered $n, array $channels) use ($admin, $new) {
            $data = $n->toArray($admin);

            return $n->user->is($new) && $channels === ['database', 'mail']
                && str_contains($data['body'], 'دانشگاه تهران') && str_contains($data['body'], 'فرم ثبت‌نام')
                && str_contains($data['body'], '+989121234567') && $data['url'] === route('admin.users.edit', $new);
        });
        Notification::assertNotSentTo($plain, UserRegistered::class);
        Notification::assertNotSentTo($new, UserRegistered::class);
    }

    public function test_the_phone_is_left_out_for_recipients_without_users_contact(): void
    {
        $editor = $this->user('editor');
        $new = User::create(['name' => 'تازه', 'email' => 'p-' . uniqid() . '@example.com', 'password' => 'x', 'role' => 'user', 'phone' => '+989121234567']);

        $body = (new UserRegistered($new))->toArray($editor)['body'];
        $this->assertStringNotContainsString('+989121234567', $body);
        $this->assertStringContainsString('+989121234567', (new UserRegistered($new))->toArray($this->user('admin'))['body']);
    }

    public function test_a_first_google_sign_in_notifies_once_and_sends_no_verification_link(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $this->fakeGoogle('g-new', 'gnew@example.com', 'رضا گوگلی');

        $this->get('/auth/google/callback')->assertRedirect();
        $new = User::where('email', 'gnew@example.com')->sole();
        Notification::assertSentTo($admin, UserRegistered::class, fn (UserRegistered $n) => str_contains($n->toArray($admin)['body'], 'ورود با گوگل'));
        Notification::assertNothingSentTo($new);

        // A returning Google user is not a new sign-up
        auth()->logout();
        $this->get('/auth/google/callback')->assertRedirect();
        Notification::assertSentToTimes($admin, UserRegistered::class, 1);
    }

    public function test_an_admin_can_switch_the_notice_off(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        \App\Notifications\NotificationPreferences::set($admin, UserRegistered::TYPE, 'mail', false);
        $new = $this->user('user');

        event(new \Illuminate\Auth\Events\Registered($new));
        Notification::assertSentTo($admin, UserRegistered::class, fn ($n, array $channels) => $channels === ['database']);
    }
}
