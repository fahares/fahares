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

    public function test_a_sentence_names_its_subject_after_a_subject_word()
    {
        $this->assertSame([['مدح', ['پیامبر اکرم که شاعر سروده است']]], KnownSubjects::fromText('قصیده‌ای است در مدح حضرت پیامبر اکرم (ص) که شاعر سروده است'));
        $this->assertSame([['فضایل و مناقب', ['امیرالمؤمنین که مؤلف گرد آورده']]], KnownSubjects::fromText('در فضایل و مناقب حضرت امیرالمؤمنین علیه السلام که مؤلف گرد آورده'));
        // Within the first characters only, and only the first word: a later «احوال» is a chapter
        $this->assertSame([], KnownSubjects::fromText('کتابی مفصل در فقه استدلالی در ده باب و یک مقدمه و یک خاتمه و چند فصل دیگر که در باب آخر آن احوال امام حسین آمده', 70));
        $this->assertCount(1, KnownSubjects::fromText('در مدح علی و در مرثیه حسین', 70));
    }

    public function test_the_head_of_the_rest_is_read_and_a_bare_name_needs_nothing_after_it()
    {
        $this->assertSame(['محمد (ص)، پیامبر اسلام', 'پیامبر اکرم'], KnownSubjects::resolve('پیامبر اکرم که شاعر سروده است'));
        $this->assertSame(['حسین بن علی (ع)، امام سوم', 'امام حسین'], KnownSubjects::resolve('امام حسین و یاران او'));
        $this->assertSame(['حسین بن علی (ع)، امام سوم', 'حسین'], KnownSubjects::resolve('حسین که در کربلا'));
        // «حسین خان»، «الحسین بن منصور»: another person
        $this->assertNull(KnownSubjects::resolve('حسین خان سردار'));
        $this->assertNull(KnownSubjects::resolve('الحسین بن منصور حلاج'));
        // A reviewed phrase
        $this->assertSame(['ابن سینا، حسین بن عبدالله', 'الشيخ الرئيس'], KnownSubjects::resolve('الشيخ الرئيس و آثار او', [\App\Support\KnownRulers::compact('الشيخ الرئيس') => 'ابن سینا، حسین بن عبدالله']));
    }

    public function test_a_joined_word_is_read_and_a_time_is_not_a_subject()
    {
        // «شرح حال و آثار …», «مدح و ستایش …»
        $this->assertSame([['شرح حال و منتخب اشعار', ['سید ذوالفقار شروانی']]], KnownSubjects::fromText('شرح حال و منتخب اشعار سید ذوالفقار شروانی'));
        $this->assertSame([['مدح و ستایش', ['فتح علی شاه', 'عباس میرزا ولی عهد']]], KnownSubjects::fromText('مدح و ستایش فتح علی شاه و عباس میرزا ولی عهد'));
        // «پس از وفات فتحعلی شاه»: when, not about whom
        $this->assertSame([], KnownSubjects::fromText('بخشی از کتابی است که پس از وفات فتحعلی شاه نوشته شده'));
        // «حضرت» and the honorifics go before the name is read
        $this->assertSame(['علی بن ابی طالب (ع)، امام اول', 'علی بن ابیطالب'], KnownSubjects::resolve(KnownSubjects::fromText('مناقب حضرت علی بن ابیطالب (ع)')[0][1][0]));
        $this->assertSame('حسن بن علی (ع)، امام دوم', KnownSubjects::find('امام مجتبی'));
        $this->assertSame('محمد بن حسن (عج)، امام دوازدهم', KnownSubjects::find('امام عصر'));
    }
}
