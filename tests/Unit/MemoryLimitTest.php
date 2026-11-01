<?php

namespace Tests\Unit;

use App\Support\MemoryLimit;
use PHPUnit\Framework\TestCase;

class MemoryLimitTest extends TestCase
{
    private string $before;

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->before);
        parent::tearDown();
    }

    public function test_a_lower_limit_is_raised(): void
    {
        ini_set('memory_limit', (string) (memory_get_usage(true) + 64 * 1024 * 1024));

        $this->assertTrue(MemoryLimit::raiseTo('4G'));
        $this->assertSame(4 * 1024 ** 3, ini_parse_quantity(ini_get('memory_limit')));
    }

    public function test_a_higher_or_unlimited_limit_is_kept(): void
    {
        ini_set('memory_limit', '8G');
        $this->assertFalse(MemoryLimit::raiseTo('192M'));
        $this->assertSame('8G', ini_get('memory_limit'));

        ini_set('memory_limit', '-1');
        $this->assertFalse(MemoryLimit::raiseTo('192M'));
        $this->assertSame('-1', ini_get('memory_limit'));
    }

    public function test_a_target_below_the_current_usage_does_not_fail(): void
    {
        // A long test run (or worker) may already use more than the target, which plain ini_set refuses with a warning
        $usage = memory_get_usage(true);
        ini_set('memory_limit', (string) ($usage + 1024 * 1024));

        $this->assertTrue(MemoryLimit::raiseTo('1M'));
        $this->assertGreaterThan($usage, ini_parse_quantity(ini_get('memory_limit')));
    }
}
