<?php

namespace Tests\Unit;

use App\Sources\Bibliography\ItemMatcher;
use PHPUnit\Framework\TestCase;

/** Which entry of a cited al-Dhari'a page a Fankha work is (plan docs/plans/dharia-text.md, link (a)), on real cases. */
class ItemMatcherTest extends TestCase
{
    private function item(int $id, ?int $number, string $title, string $after): array
    {
        return ['id' => $id, 'entry_number' => $number, 'title' => $title, 'text' => "( {$number} : {$title} ) {$after}"];
    }

    private function tuhfaPage(): array
    {
        return [
            $this->item(1, 1443, 'التحفة', 'في تحديد الكر وتقديره وزنا ومساحة ، فارسي للشيخ بهاء الدين محمد بن الشيخ عز الدين الحسين بن عبد الصمد الحارثي الجبعي العاملي المتوفى سنة 1031'),
            $this->item(2, 1445, 'التحفة', 'في الخواتيم للعلامة الكراجكي الشيخ أبي الفتح محمد بن علي بن عثمان المتوفى سنة 449'),
            $this->item(3, 1447, 'التحفة', 'في الطب ويقال له تحفة المؤمن أو تحفة المؤمنين ، للسيد الحكيم الشهير ب‍ " حكيم مؤمن " الأمير محمد مؤمن بن محمد زمان الحسيني التنكابني'),
        ];
    }

    public function test_the_author_tells_apart_entries_of_one_title(): void
    {
        $match = (new ItemMatcher)->match(['تحفه حکیم مؤمن'], 'حکیم مؤمن، محمد مؤمن بن محمد زمان', 1050, $this->tuhfaPage());
        // «تحفه حکیم مؤمن» is no heading on the page, but the author's names are only in 1447
        $this->assertSame([3, 'entry'], [$match['id'], $match['role']]);

        $match = (new ItemMatcher)->match(['التحفة'], 'کراجکی، محمد بن علی', 449, $this->tuhfaPage());
        $this->assertSame([2, 'title_author', 95], [$match['id'], $match['method'], $match['confidence']]);
    }

    public function test_the_entry_number_fankha_gives_wins(): void
    {
        $match = (new ItemMatcher)->match(['تحفه رضویه'], 'نراقی، مهدی بن ابی ذر', 1209, $this->tuhfaPage(), 1445);
        $this->assertSame([2, 'cited_number', 100], [$match['id'], $match['method'], $match['confidence']]);
    }

    public function test_the_subject_after_the_heading_completes_the_title(): void
    {
        $page = [
            $this->item(1, 1452, 'تحفة الأبرار', 'لآقا محمد جعفر بن آقا محمد علي بن الوحيد البهبهاني الكرمانشاهاني المتوفى سنة 1254'),
            $this->item(2, 1453, 'تحفة الأبرار', 'في أصول الدين فارسي للشيخ الفقيه المتكلم عماد الدين الحسن بن علي بن محمد بن علي بن الحسن الطبرسي الآملي'),
            $this->item(3, 1454, 'تحفة الأبرار', 'في مناقب أبي الأئمة الأطهار عليهم السلام للسيد حسين بن مساعد بن الحسن الحائري'),
        ];
        // Fankha writes «طبری» where al-Dhari'a has «الطبرسي»: the subject decides
        $match = (new ItemMatcher)->match(['تحفة الابرار فی اصول الدین'], 'طبری، حسن بن علی', 650, $page);
        $this->assertSame([2, 'entry', 'full_title'], [$match['id'], $match['role'], $match['method']]);
    }

    public function test_a_commentary_found_at_its_books_heading_is_a_mention(): void
    {
        $page = [$this->item(1, 4, 'الباب الحادي عشر', 'في الكلام هو آخر أبواب منهاج الصلاح في مختصر المصباح لآية الله العلامة الحلي المتوفى سنة 726')];
        $match = (new ItemMatcher)->match(['شرح الباب الحادی عشر'], 'مشهدی، محمد بن حسین', 1050, $page);
        $this->assertSame(['mention', 'derivative_of_heading'], [$match['role'], $match['method']]);
    }

    public function test_a_work_named_inside_another_entry_is_a_mention_and_an_unknown_one_nothing(): void
    {
        $page = [$this->item(1, 6151, 'ديوان گلشنى بردعي', 'إبراهيم بن محمد بن الحاج إبراهيم بن شهاب الدين البردعي . وله ( أزهار گلشن ) موجودة في ( المجلس )')];
        $match = (new ItemMatcher)->match(['ازهار گلشن'], 'گلشنی بردعی، ابراهیم بن محمد', 940, $page);
        $this->assertSame([1, 'mention', 'title_in_text', 60], [$match['id'], $match['role'], $match['method'], $match['confidence']]);

        $this->assertNull((new ItemMatcher)->match(['تاج المآثر'], 'نظامی نیشابوری، حسن بن محمد', 626, $page));
    }

    public function test_short_titles_need_to_be_close(): void
    {
        $page = [$this->item(1, 900, 'تاريخ تستر', 'للسيد عبد الله بن السيد نور الدين الجزائري المتوفى 1173')];
        $match = (new ItemMatcher)->match(['تاریخ تبریز'], 'ثریا قاجار، نادر میرزا', 1244, $page);
        $this->assertNull($match);
    }
}
