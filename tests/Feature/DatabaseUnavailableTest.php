<?php

namespace Tests\Feature;

use App\Support\DatabaseUnavailable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Backlog item 136, part 3 (step 14.412): a database outage answers 503 with the fahares-down page. */
class DatabaseUnavailableTest extends TestCase
{
    private function dbError(string $message, int $code): QueryException
    {
        $pdo = new \PDOException($message);
        $pdo->errorInfo = ['HY000', $code, $message];

        return new QueryException('mariadb', 'select * from sessions where id = ?', ['x'], $pdo);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/__test-db-refused', fn () => throw $this->dbError('SQLSTATE[HY000] [2002] Connection refused', 2002));
        Route::get('/__test-db-shutdown', fn () => throw $this->dbError('SQLSTATE[08S01]: Server shutdown in progress', 1053));
        Route::get('/__test-db-syntax', fn () => throw $this->dbError('SQLSTATE[42000]: Syntax error', 1064));
    }

    public function test_a_refused_connection_shows_the_down_page_with_503(): void
    {
        $response = $this->get('/__test-db-refused');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', DatabaseUnavailable::RETRY_AFTER);
        $response->assertHeader('X-Robots-Tag', 'noindex');
        $response->assertSee('سایت چند لحظه در دسترس نیست');
        // /up answers 200 in a database outage, so the page polls its own address
        $response->assertSee('var probe = location.href;', false);
        $response->assertDontSee("var probe = '/up';", false);
    }

    public function test_a_server_shutting_down_counts_too(): void
    {
        $this->get('/__test-db-shutdown')->assertStatus(503);
        $this->getJson('/__test-db-shutdown')->assertStatus(503)->assertJsonStructure(['message']);
    }

    public function test_other_database_errors_stay_500(): void
    {
        $this->get('/__test-db-syntax')->assertStatus(500);
        $this->assertFalse(DatabaseUnavailable::causedBy(new \RuntimeException('boom')));
    }
}
