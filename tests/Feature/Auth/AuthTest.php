<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_user_can_view_login_page(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('ورود به حساب کاربری');
    }

    public function test_user_can_view_register_page(): void
    {
        $response = $this->get('/register');
        $response->assertStatus(200);
        $response->assertSee('ثبت‌نام پژوهشگر');
    }

    public function test_user_can_register(): void
    {
        $email = 'researcher-' . uniqid() . '@example.com';
        $response = $this->post('/register', [
            'name' => 'پژوهشگر آزمایشی',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'affiliation' => 'دانشگاه فردوسی مشهد',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertEquals('user', $user->role);
        $this->assertEquals('دانشگاه فردوسی مشهد', $user->affiliation);

        $user->delete();
    }

    public function test_user_can_login_and_logout(): void
    {
        $email = 'login-test-' . uniqid() . '@example.com';
        $user = User::create([
            'name' => 'تست ورود',
            'email' => $email,
            'password' => 'secret1234',
            'role' => 'user',
        ]);

        $response = $this->post('/login', [
            'email' => $email,
            'password' => 'secret1234',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $logoutResponse = $this->post('/logout');
        $logoutResponse->assertRedirect('/');
        $this->assertGuest();

        $user->delete();
    }
}
