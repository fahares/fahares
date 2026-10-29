<?php

namespace Tests\Unit;

use App\Support\WorkRelationParser;
use PHPUnit\Framework\TestCase;

/**
 * The relation and base title read from a title or a related work recorded as text (backlog items 6 and 95)
 */
class WorkRelationParserTest extends TestCase
{
    public function test_an_opening_word_names_the_base_work()
    {
        $this->assertSame(['type' => 'commentary', 'base' => 'نهج البلاغة', 'head' => null], WorkRelationParser::parseTitle('شرح نهج البلاغة'));
        $this->assertSame(['type' => 'gloss', 'base' => 'فرائد الاصول', 'head' => null], WorkRelationParser::parseTitle('تعليقة على فرائد الاصول'));
        $this->assertSame(['type' => 'gloss', 'base' => 'الشرائع', 'head' => null], WorkRelationParser::parseTitle('حاشیة علی الشرائع'));
        $this->assertSame(['type' => 'abridgment', 'base' => 'مصباح السالکین', 'head' => null], WorkRelationParser::parseTitle('مختصر مصباح السالکین'));
        $this->assertSame(['type' => 'refutation', 'base' => 'صوفیه', 'head' => null], WorkRelationParser::parseTitle('رد بر صوفیه'));
        $this->assertSame(['type' => 'supplement', 'base' => 'نهج البلاغة', 'head' => null], WorkRelationParser::parseTitle('مستدرک نهج البلاغة'));
    }

    public function test_a_half_space_is_a_space()
    {
        $this->assertSame('نهج البلاغه', WorkRelationParser::parseTitle("شرح نهج\u{200c}البلاغه")['base']);
    }

    public function test_a_mark_after_the_title()
    {
        $this->assertSame(['type' => 'translation', 'base' => 'الرسالة الذهبیة', 'head' => null], WorkRelationParser::parseTitle('الرسالة الذهبیة (ترجمه)'));
        $this->assertNull(WorkRelationParser::parseTitle('شرح القصيدة النونية (6عنوان)')['head']);
    }

    public function test_a_title_of_its_own_before_the_base()
    {
        $this->assertSame(['type' => 'commentary', 'base' => 'اللمعة الدمشقیة', 'head' => 'الروضة البهیة'], WorkRelationParser::parseTitle('الروضة البهیة فی شرح اللمعة الدمشقیة'));
        $this->assertSame(['type' => 'commentary', 'base' => 'نهج البلاغه', 'head' => 'منهاج البراعه'], WorkRelationParser::parseTitle('منهاج البراعه فی شرح نهج البلاغه'));
    }

    public function test_a_commentary_inside_a_title_without_a_joining_word()
    {
        $this->assertSame(['type' => 'commentary', 'base' => 'اللمعة الدمشقیة', 'head' => 'توضیح'], WorkRelationParser::parseTitle('توضیح شرح اللمعة الدمشقیة'));
        $this->assertSame(['type' => 'commentary', 'base' => 'نهج البلاغة لابن ابی الحدید', 'head' => 'المنتخب'], WorkRelationParser::parseTitle('المنتخب من شرح نهج البلاغة لابن ابی الحدید'));
        $this->assertNull(WorkRelationParser::parseTitle('کتاب مختصر'));
    }

    public function test_titles_that_name_no_other_work()
    {
        $this->assertNull(WorkRelationParser::parseTitle('نهج البلاغة'));
        $this->assertNull(WorkRelationParser::parseTitle('شرح'));
        $this->assertNull(WorkRelationParser::parseTitle('شرح حال ابن سینا'));
        $this->assertNull(WorkRelationParser::parseTitle('رساله در شرح حال مولانا'));
        $this->assertNull(WorkRelationParser::parseTitle('منظومة فی النحو'));
        $this->assertNull(WorkRelationParser::parseTitle('المختصر النافع'));
        $this->assertNull(WorkRelationParser::parseTitle('شرح کبیر'));
        $this->assertNull(WorkRelationParser::parseTitle(null));
    }

    public function test_an_entry_with_its_author_and_dates()
    {
        $this->assertSame([[
            'titles' => ['حاشیة انوار التنزیل', 'حاشیه تفسیر بیضاوی'],
            'author' => 'سعدی چلپی، سعدالله بن عیسی', 'born' => null, 'died' => 945,
            'type' => 'gloss', 'base' => 'انوار التنزیل',
        ]], WorkRelationParser::parseEntries('حاشیة انوار التنزیل = حاشیه تفسیر بیضاوی؛ سعدی چلپی، سعدالله بن عیسی (-945)'));
    }

    public function test_several_entries_and_a_closing_note()
    {
        $entries = WorkRelationParser::parseEntries('جلاء العیون؛ مجلسی، محمد باقر بن محمد تقی (1037-1110)؛ اللهوف علی قتلی الطفوف = لهوف؛ ابن طاوس، علی بن موسی (589-664)؛ منتخبی است از دو کتاب «جلاء العیون» علامه مجلسی و «الملهوف= لهوف» ابن طاوس که در ده باب مرتب شده است.');
        $this->assertSame([['جلاء العیون'], ['اللهوف علی قتلی الطفوف', 'لهوف']], array_column($entries, 'titles'));
        $this->assertSame([1037, 589], array_column($entries, 'born'));
    }

    public function test_a_note_after_the_dates_ends_the_field()
    {
        $entries = WorkRelationParser::parseEntries('الیاقوت؛ نوبختی، ابراهیم بن اسحاق (-4) شرحی است از علامه حلی بر «الیاقوت»؛ 2. جوهر و عرض، دارای ده مسأله؛ 3. احکام جوهر');
        $this->assertSame([['الیاقوت']], array_column($entries, 'titles'));
        $this->assertSame('نوبختی، ابراهیم بن اسحاق', $entries[0]['author']);
    }

    public function test_an_entry_without_author_and_corpus_markers()
    {
        $this->assertSame([['اماطة اللثام']], array_column(WorkRelationParser::parseEntries('اماطة اللثام'), 'titles'));
        $entries = WorkRelationParser::parseEntries('الاشعثیات = الجعفریات؛ ابن اشعث، محمد بن محمد <!-- page: 1022 --> (-314ق) [فهرستگان حدیث، ج3، ص357-358]');
        $this->assertSame(['الاشعثیات', 'الجعفریات'], $entries[0]['titles']);
        $this->assertSame(['ابن اشعث، محمد بن محمد', 314], [$entries[0]['author'], $entries[0]['died']]);
        $this->assertSame([], WorkRelationParser::parseEntries(null));
    }

    public function test_an_author_after_an_equals_sign()
    {
        $entries = WorkRelationParser::parseEntries('اللمعة الدمشقیة فی فقه الامامیة = شهید اول، محمد بن مکی (734-786)');
        $this->assertSame([['اللمعة الدمشقیة فی فقه الامامیة']], array_column($entries, 'titles'));
        $this->assertSame(['شهید اول، محمد بن مکی', 786], [$entries[0]['author'], $entries[0]['died']]);
    }

    public function test_a_commentaries_entry_with_its_mark()
    {
        $entries = WorkRelationParser::parseEntries('المصباح فی اسرار علم المفتاح (ترجمه)؛ موسوی زنجانی، ابراهیم (1304-1394)');
        $this->assertSame(['translation', 'المصباح فی اسرار علم المفتاح'], [$entries[0]['type'], $entries[0]['base']]);
    }
}
