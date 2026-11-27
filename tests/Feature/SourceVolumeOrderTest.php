<?php

namespace Tests\Feature;

use App\Models\SourceVolume;
use Tests\TestCase;

/** Step 14.440: the rows of one printed volume are listed by the part their title names, not by their ids. */
class SourceVolumeOrderTest extends TestCase
{
    public function test_the_part_of_a_volume_is_read_from_its_title()
    {
        $part = fn (int $number, string $title) => (new SourceVolume(['volume_number' => $number, 'title' => $title]))->partOrder();

        $this->assertSame(1, $part(40, 'فهرست کتابخانه مجلس شورای اسلامی - جلد ۱/۴۰: اهدایی کریم‌زاده تبریزی'));
        $this->assertSame(2, $part(40, 'فهرست کتابخانه مجلس شورای اسلامی - جلد ۲/۴۰: اهدایی کریم‌زاده تبریزی'));
        $this->assertSame(3, $part(40, 'فهرست کتابخانه مجلس شورای اسلامی - جلد ۳/۴۰'));
        // the volume before the slash, and Latin digits
        $this->assertSame(2, $part(24, 'فهرست … - جلد 24/2'));
        $this->assertSame(2, $part(9, 'فهرست کتابخانه مجلس شورای اسلامی - جلد نهم - بخش دوم'));
        $this->assertSame(4, $part(10, 'فهرست کتابخانه مجلس شورای اسلامی - جلد دهم - بخش چهارم'));
        $this->assertSame(3, $part(10, 'فهرست … - جلد دهم (بخش ۳)'));
        $this->assertSame(0, $part(39, 'فهرست کتابخانه مجلس شورای اسلامی - جلد سی و نهم'));
    }

    public function test_the_rows_of_a_volume_are_sorted_by_their_part()
    {
        $rows = collect([[438, 40, 'ف - جلد ۲/۴۰'], [647, 40, 'ف - جلد ۳/۴۰'], [745, 40, 'ف - جلد ۱/۴۰'], [646, 39, 'ف - جلد سی و نهم'], [649, 51, 'ف - جلد پنجاه و یکم']])
            ->map(fn ($r) => (new SourceVolume(['volume_number' => $r[1], 'title' => $r[2]]))->forceFill(['id' => $r[0]]));

        $this->assertSame([646, 745, 438, 647, 649], $rows->sortBy(fn ($v) => [$v->volume_number, $v->partOrder(), $v->id])->pluck('id')->all());
    }
}
