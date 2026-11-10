<?php

namespace Tests\Unit;

use App\Helpers\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    /** A query pasted with direction marks found nothing in search_logs (13 of 13 searches); they are dropped */
    public function test_direction_marks_are_removed(): void
    {
        $this->assertSame('دیوان', TextNormalizer::normalize("\u{202B}دیوان"));
        $this->assertSame('قره العیون', TextNormalizer::normalize("\u{202B}\u{202B}\u{202B}قرة العیون\u{202C}"));
        $this->assertSame('IR10-19601', TextNormalizer::normalize("IR\u{202D}10-19601\u{202C}"));
        $this->assertSame('کافی', TextNormalizer::normalize("\u{FEFF}\u{200F}کافی\u{200E}\u{2067}\u{2069}\u{061C}"));
    }

    /** ZWNJ still splits the words, as before */
    public function test_zwnj_still_becomes_a_space(): void
    {
        $this->assertSame('نهج البلاغه', TextNormalizer::normalize("نهج\u{200c}البلاغه"));
    }
}
