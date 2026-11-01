<?php

namespace Tests\Unit;

use App\Support\KnownSubjects;
use PHPUnit\Framework\TestCase;

/** The subject a work's title names (backlog item 35) */
class KnownSubjectsTest extends TestCase
{
    public function test_a_titles_word_and_subject_pieces_are_read()
    {
        $this->assertSame(['مقتل', ['الحسین']], KnownSubjects::fromTitle('مقتل الحسین (ع)'));
        $this->assertSame(['شرح حال', ['امام رضا']], KnownSubjects::fromTitle('شرح حال امام رضا علیه السلام'));
        // «ا» survives: trim() with «،» would have cut its first byte
        $this->assertSame(['احوال', ['امام رضا']], KnownSubjects::fromTitle('احوال امام رضا'));
        $this->assertSame(['احوال', ['پیامبر', 'ائمه']], KnownSubjects::fromTitle('احوال پیامبر (ص) و ائمه علیهم السلام'));
        $this->assertNull(KnownSubjects::fromTitle('رساله در منطق'));
    }

    public function test_the_prophet_fatima_and_the_imams_are_found_by_their_spellings()
    {
        $this->assertSame('حسین بن علی (ع)، امام سوم', KnownSubjects::find('الحسین'));
        $this->assertSame('حسین بن علی (ع)، امام سوم', KnownSubjects::find('سید الشهداء'));
        $this->assertSame('علی بن ابی طالب (ع)، امام اول', KnownSubjects::find('امیر المؤمنین'));
        $this->assertSame('علی بن ابی طالب (ع)، امام اول', KnownSubjects::find('مرتضوی'));
        $this->assertSame('محمد (ص)، پیامبر اسلام', KnownSubjects::find('مصطفی'));
        $this->assertSame('فاطمه زهرا (س)', KnownSubjects::find('فاطمة'));
        $this->assertNull(KnownSubjects::find('ائمه'));
        $this->assertNull(KnownSubjects::find('شاه نعمت الله ولی'));
    }
}
