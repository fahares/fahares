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
