<?php

namespace Tests\Feature;

use App\Helpers\SearchHelper;
use Tests\TestCase;

/** Step 14.158: a found word is marked without breaking the word it is part of */
class SearchMarkTest extends TestCase
{
    public function test_a_mark_inside_a_word_keeps_its_letters_joined()
    {
        $zwj = "\u{200D}";

        // «آتشک» in «آتشکده»: «ک» joins «د», so a joiner stands on each side of the cut
        $this->assertSame('<mark class="search-mark">آتشک' . $zwj . '</mark>' . $zwj . 'ده آذر', SearchHelper::highlight('آتشکده آذر', 'آتشک'));

        // a whole word, and a cut after a letter that never joins the next one («ر»), need none
        $this->assertSame('<mark class="search-mark">آذر</mark> بیگدلی', SearchHelper::highlight('آذر بیگدلی', 'آذر'));
        $this->assertSame('<mark class="search-mark">آذر</mark>بایجان', SearchHelper::highlight('آذربایجان', 'آذر'));

        // a cut at the start of the mark, inside a word
        $this->assertSame('کتاب' . $zwj . '<mark class="search-mark">' . $zwj . 'خانه</mark>', SearchHelper::highlight('کتابخانه', 'خانه'));
    }

    public function test_the_engines_marks_become_the_same_tags()
    {
        $this->assertSame('نسخه‌ی <mark class="search-mark">دیوان</mark> حافظ', SearchHelper::marked("نسخه‌ی \x01دیوان\x02 حافظ"));
    }
}
