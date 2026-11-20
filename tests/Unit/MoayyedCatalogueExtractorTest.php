<?php

namespace Tests\Unit;

use App\Sources\Extractors\Extractors;
use App\Sources\Extractors\MoayyedCatalogueExtractor;
use PHPUnit\Framework\TestCase;

/** The Mo'ayyad catalogue as the Ghaemiyeh reader gives it (step 14.258), from pages of its three volumes, shortened. */
class MoayyedCatalogueExtractorTest extends TestCase
{
    /** Vol 1 (Arabic): titles before their numbers, an author line between, and composite volumes. */
    private const VOL1 = [
        44 => "النسخ، 1222.\n114 ورقة ، 22 × 15 سم، 20 سطراً.\nشرح الشافية  [الصرف]\n5\nلفخر الدين الجاربردي.\nشرح على الشافية.\n"
            . "النسخ ، محمد علي البيدگلي، 1222.\n162 ورقة، 20 × 14 سم ، 19 سطراً.",
        // the number «6» is lost in the text: «مجموعة» heads the page
        45 => "مجموعة\nالحاشية على كتاب في الحكمة (1 ظ - 106 ظ)  [الفلسفة]\nللملا میرزا جان.\n"
            . "تلخيص كتاب «المقولات» لأرسطو (106ظ - 128 و) [المنطق ]\nل : ؟\nتلخیص کتاب لأرسطو.\n"
            . "النسخ، 1100.\n140 ورقة، 21 × 15 سم، 17 سطراً.\nقواعد العقائد [الكلام]\n7\nللخواجة نصير الدين.\nالنسخ، 1050.\n90 ورقة، 20 × 14 سم.",
    ];

    /** Vol 1 p. 137: a composite volume whose first treatise stands before its number. */
    private const VOL1_145 = [
        137 => "مجموعة\nرسالة في بيان ما وجد بخط أمير المؤمنين له (1 ظ _ 9و) [العلوم الغريبة]\n145\nللغزالي.\n"
            . "الفوائد الحكميّة (9ظ۔ 44ظ) [العقائد]\nللإحسائي.\n"
            . "رسالة في دوام التنعّم والعذاب (73ظ 81ظ] [العقائد]\nل : ؟\n"
            . "جوابات السلطان فتحعلی شاه (81ظ - 93 ظ) [العقائد]\nللإحسائي.\nالنسخ، 1249.\n216 ورقة ، 21 × 14 سم ، 22 سطراً.",
    ];

    /** Vol 1 p. 391: two copies, the second with its author's line between title and number. */
    private const VOL1_484 = [
        391 => "رسالة في البداء [العقائد]\n483\nل:؟\nمجموعة تحوي كلمات العلماء.\nالنسخ.\n54 ورقة ، 18 × 10 سم ، 19 سطراً.\n"
            . "السرّ المنير [العلوم الغريبة]\nل : ؟\n484\nكتاب في علم البسط والتكسير.\nالنستعليق.\n102 ورقة ، 19 × 12 سم ، 11 سطراً.",
    ];

    /** Vol 3 (Persian): number and title on one line, numbered treatises, a line that begins with a year, the appendix. */
    private const VOL3 = [
        39 => "1026 شرح صمديه\n[ نحو ]\nاز:؟\nشرحی است بر کتاب صمدیه.\nنستعلیق، محمد نوری، 1341.\n"
            . "1166 با مهر بیضوی «العبد میرزا سید» دیده می‌شود.\n140 برگ، 20/5 × 13 سم، 20 سطر\n"
            . "1027 مجموعه\n1- سراج القلوب (2 و 65 پ) [ تاریخ ]\nاز: ابو بصیر قطان غزنوی\n"
            . "2 - خواص سور واذکار (66 پ. 70 ر) [دعا ]\nاز:؟\nنستعلیق، 1297.\n120 برگ، 18 × 11 سم، 15 سطر",
        40 => "1028 تحفة الابرار/سید محمد باقر رشتی\nبه نسخه 1131 مراجعه شود.\nنسخ، 1246.\n370 برگ، 29 × 19 سم، 22 سطر",
        521 => "مستدرك فهرس مخطوطات\n1 شرح التصريف\nمكتبة الخطيب الشيخ علي حيدر المؤيد",
        523 => "[الصرف]\nلعلي بن محمد الأقدري\nالنستعلیق، 975.\n115 ورقة ، 17 × 12 سم ، 17 سطر.",
        524 => "2 مجموعة\n1: تكملة تعليقه برنفحات الانس (2 پ. 22 ر) [التاريخ ]\nاز : ملا عبد الغفور لاری\n"
            . "2: دیوان مظالم (24 پ. 30 ر) [اخلاق]\nرساله ای است در کیفیت قضاوت.\nالنسخ.\n40 ورقة، 20 × 12 سم.",
    ];

    private function read(array $pages, string $shelfmark, int $page): ?array
    {
        return (new MoayyedCatalogueExtractor)->extract($pages, $shelfmark, $page);
    }

    public function test_it_is_a_known_format()
    {
        $this->assertSame(MoayyedCatalogueExtractor::class, Extractors::FORMATS['moayyed']);
    }

    public function test_a_title_before_its_number_is_part_of_the_entry()
    {
        $found = $this->read(self::VOL1, '5', 44);
        $this->assertSame("شرح الشافية  [الصرف]\n5\nلفخر الدين الجاربردي.\nشرح على الشافية.", $found['entry_text']);
        $this->assertSame("النسخ ، محمد علي البيدگلي، 1222.\n162 ورقة، 20 × 14 سم ، 19 سطراً.", $found['copy_text']);
        $this->assertSame(44, $found['page_start']);
    }

    public function test_an_author_line_may_stand_between_the_title_and_the_number()
    {
        $found = $this->read(self::VOL1_484, '484', 391);
        $this->assertStringStartsWith("السرّ المنير [العلوم الغريبة]\nل : ؟\n484\n", $found['entry_text']);
        // and the entry before it ends at that title
        $this->assertStringNotContainsString('السرّ المنير', $this->read(self::VOL1_484, '483', 391)['copy_text']);
    }

    public function test_a_composite_volume_whose_number_is_lost_takes_the_number_between_its_neighbours()
    {
        $found = $this->read(self::VOL1, '6/2', 45);
        $this->assertNotNull($found);
        $this->assertStringContainsString('تلخيص كتاب «المقولات»', $found['entry_text']);
        $this->assertStringNotContainsString('الحاشية على كتاب في الحكمة', $found['entry_text']);
        // the copy after it keeps its own number
        $this->assertStringStartsWith("قواعد العقائد [الكلام]\n7", $this->read(self::VOL1, '7', 45)['entry_text']);
    }

    public function test_the_first_treatise_before_the_number_counts_and_a_misprinted_bracket_does_not_lose_one()
    {
        $first = $this->read(self::VOL1_145, '145/1', 137);
        $this->assertStringStartsWith('رسالة في بيان ما وجد بخط أمير المؤمنين', $first['entry_text']);
        // «(73ظ 81ظ]» is the third treatise, so the fourth is the Shah's answers
        $fourth = $this->read(self::VOL1_145, '145/4', 137);
        $this->assertStringContainsString('جوابات السلطان فتحعلی شاه', $fourth['entry_text']);
        $this->assertStringNotContainsString('دوام التنعّم', $fourth['entry_text']);
        // shown with its volume's number
        $this->assertStringStartsWith("145\n", $fourth['entry_text']);
        // a treatise the volume does not have is not taken as the whole volume
        $this->assertNull($this->read(self::VOL1_145, '145/9', 137));
    }

    public function test_a_persian_heading_holds_number_and_title_and_a_year_is_not_a_heading()
    {
        $found = $this->read(self::VOL3, '1026', 39);
        $this->assertSame("1026 شرح صمديه\n[ نحو ]\nاز:؟\nشرحی است بر کتاب صمدیه.", $found['entry_text']);
        $this->assertStringContainsString('1166 با مهر بیضوی', $found['copy_text']);
        $this->assertNull($this->read(self::VOL3, '1166', 39));
    }

    public function test_numbered_treatises_and_the_next_heading_without_a_subject()
    {
        $found = $this->read(self::VOL3, '1027/2', 39);
        $this->assertSame("1027 مجموعه\n2 - خواص سور واذکار (66 پ. 70 ر) [دعا ]\nاز:؟", $found['entry_text']);
        $this->assertSame("نستعلیق، 1297.\n120 برگ، 18 × 11 سم، 15 سطر", $found['copy_text']);
        // «1028 …/…» follows 1027, so it is a heading though no subject line comes with it
        $this->assertStringStartsWith('1028 تحفة الابرار', $this->read(self::VOL3, '1028', 40)['entry_text']);
    }

    public function test_the_appendix_numbers_from_one_again()
    {
        $found = $this->read(self::VOL3, '1', 521);
        $this->assertStringStartsWith('1 شرح التصريف', $found['entry_text']);
        $second = $this->read(self::VOL3, '2/2', 524);
        $this->assertStringContainsString('2: دیوان مظالم', $second['entry_text']);
        $this->assertStringNotContainsString('تكملة', $second['entry_text']);
    }
}
