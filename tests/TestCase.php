<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages render without a frontend build (public/build/manifest.json)
        $this->withoutVite();
    }

    /**
     * Suggestions come only from signed-in users (backlog item 76): sends one as a researcher account,
     * then returns to whoever was signed in before (or to a guest).
     */
    protected function suggestJson(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->asResearcher(fn () => $this->postJson('/suggestions', $data));
    }

    protected function suggestPost(array $data, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->asResearcher(fn () => $this->post('/suggestions', $data, $headers));
    }

    protected function suggestingUser(): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'test-researcher@fahares.net'],
            ['name' => 'پژوهشگر آزمون', 'password' => 'password123', 'role' => 'user']
        );
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user;
    }

    private function asResearcher(callable $send): \Illuminate\Testing\TestResponse
    {
        $previous = auth()->user();
        if (! $previous) {
            $this->actingAs($this->suggestingUser());
        }
        $response = $send();
        if (! $previous) {
            $this->app['auth']->forgetGuards();
        }

        return $response;
    }

    /**
     * RefreshDatabase drops every table, so refuse to run unless the connection
     * really points at a dedicated test database (e.g. a cached config or a
     * missing force="true" in phpunit.xml would otherwise hit fahares_db).
     */
    protected function beforeRefreshingDatabase()
    {
        $database = DB::connection()->getDatabaseName();

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against non-test database [{$database}].");
        }
    }
}
