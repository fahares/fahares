<?php

namespace Tests\Unit;

use App\Support\Jalali;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class JalaliTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dates_are_jalali_in_tehran_time_with_persian_digits()
    {
        // 12:30 UTC is 16:00 in Tehran (UTC+03:30, no DST since 1401)
        $date = Carbon::parse('2026-10-20 12:30:45', 'UTC');

        $this->assertSame('۱۴۰۵/۰۷/۲۸', Jalali::format($date));
        $this->assertSame('۱۴۰۵/۰۷/۲۸، ۱۶:۰۰', Jalali::format($date, 'datetime'));
        $this->assertSame('۱۴۰۵/۰۷/۲۸، ۱۶:۰۰:۴۵', Jalali::format($date, 'seconds'));
        $this->assertSame('۲۸ مهر ۱۴۰۵', Jalali::format($date, 'long'));
        $this->assertSame('۲۸ مهر ۱۴۰۵، ۱۶:۰۰', Jalali::format($date, 'long_time'));
        $this->assertSame('۱۴۰۵', Jalali::format($date, 'year'));
    }

    public function test_tehran_time_can_cross_into_the_next_jalali_day()
    {
        // 22:00 UTC on 20 Oct is 01:30 on 21 Oct in Tehran: 29 Mehr
        $this->assertSame('۱۴۰۵/۰۷/۲۹', Jalali::format(Carbon::parse('2026-10-20 22:00', 'UTC')));
    }

    public function test_nowruz_boundary()
    {
        $this->assertSame('۱۴۰۵/۰۱/۰۱', Jalali::format(Carbon::parse('2026-03-21 12:00', 'UTC')));
        $this->assertSame('۱۴۰۴/۱۲/۲۹', Jalali::format(Carbon::parse('2026-03-20 12:00', 'UTC')));
    }

    public function test_null_gives_an_empty_string()
    {
        $this->assertSame('', Jalali::format(null));
        $this->assertSame('', Jalali::ago(null));
    }

    public function test_relative_time_is_in_persian()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'UTC'));

        $this->assertSame('۳ ساعت پیش', Jalali::ago(now()->subHours(3)));
        $this->assertSame('۲ روز پیش', Jalali::ago(now()->subDays(2)));
        $this->assertSame('۳ ساعت دیگر', Jalali::ago(now()->addHours(3)->addMinute()));
    }

    public function test_blade_directives()
    {
        $date = Carbon::parse('2026-10-20 12:30', 'UTC');

        $this->assertSame('۱۴۰۵/۰۷/۲۸', Blade::render('@jdate($d)', ['d' => $date]));
        $this->assertSame('۲۸ مهر ۱۴۰۵', Blade::render("@jdate(\$d, 'long')", ['d' => $date]));

        Carbon::setTestNow($date->copy()->addHours(3));
        $this->assertSame('۳ ساعت پیش', Blade::render('@jago($d)', ['d' => $date]));
    }
}
