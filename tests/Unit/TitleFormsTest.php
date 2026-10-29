<?php

namespace Tests\Unit;

use App\Helpers\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * A title's indexed forms keep its words side by side (backlog item 93)
 */
class TitleFormsTest extends TestCase
{
    public function test_each_form_is_the_whole_title_in_order()
    {
        $this->assertSame(['نهج البلاغه', 'نهج بلاغه', 'نهج البلاغت', 'نهج بلاغت'], TextNormalizer::titleForms('نهج البلاغة'));
    }

    public function test_the_title_as_written_comes_first()
    {
        $this->assertSame(['الکافی', 'کافی'], TextNormalizer::titleForms('الكافي'));
        $this->assertSame(['جنگ'], TextNormalizer::titleForms('جنگ'));
        $this->assertSame([], TextNormalizer::titleForms(null));
    }

    public function test_short_words_keep_their_article()
    {
        $this->assertSame(['رساله فی الله'], TextNormalizer::titleForms('رساله فی الله'));
    }
}
