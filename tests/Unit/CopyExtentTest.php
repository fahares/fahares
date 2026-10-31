<?php

namespace Tests\Unit;

use App\Support\CopyExtent;
use PHPUnit\Framework\TestCase;

/**
 * A copy's leaves, pages and their range in a volume, read from its Fankha text (backlog item 97)
 */
class CopyExtentTest extends TestCase
{
    private function extent(string $text): array
    {
        $e = CopyExtent::fromText($text);

        return [$e['folios'], $e['pages'], $e['range_from'], $e['range_to']];
    }

    public function test_leaves_pages_and_ranges()
    {
        $this->assertSame([45, null, null, null], $this->extent("خط: نسخ، بی‌کا، تا: قرن 13؛ 45گ، 14 سطر"));
        $this->assertSame([10, null, '34پ', '43ر'], $this->extent('کاغذ: فرنگی، 10گ (34پ-43ر)، 15 سطر'));
        $this->assertSame([null, 28, '1', '28'], $this->extent('28ص (1-28)، 20 سطر'));
        $this->assertSame([86, 170, null, null], $this->extent('86گ، 170ص، 17 سطر'));
        $this->assertSame([1, null, '24ر', '24ر'], $this->extent('1گ (24ر) [ف: 3-12]'));
        $this->assertSame([12, null, '37', '48'], $this->extent('12گ، 37-48، 9 سطر'));
        // Persian digits, «برگ», a citation closing the line, and a range written backwards
        $this->assertSame([3, null, '1', '3'], $this->extent('۳برگ (۱-۳)'));
        $this->assertSame([2, null, null, null], $this->extent('بی‌کا، بی‌تا؛ 2گ [ف: 3-1233]'));
        $this->assertSame([null, 3, '374', '376'], $this->extent('3ص (376-374)'));
    }

    public function test_the_physical_part_after_an_incipit_and_what_is_not_a_count()
    {
        $this->assertSame([23, null, '29پ', '52ر'], $this->extent('آغاز: برابر، انجام … است؛ خط: نستعلیق، تا: قرن 10؛ 23گ (29پ-52ر)، 14 سطر'));
        $this->assertSame([null, null, null, null], $this->extent('ضمن 21 برگ عبارات مختلفه ضمیمه است؛ 13 سطر'));
        // Two leaf counts: counted, so the caller can leave the copy alone
        $this->assertSame(2, CopyExtent::fromText('216گ (21×31)، 25گ (15×23)')['counts']);
        $this->assertSame(1, CopyExtent::fromText('346ص، 244 گ')['counts']);
    }
}
