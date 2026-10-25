<?php

namespace Tests\Unit;

use App\Sources\Bibliography\NumberedEntrySegmenter;
use PHPUnit\Framework\TestCase;

/** al-Dhari'a's pages as ablibrary gives them (plan docs/plans/dharia-text.md), cut short. */
class NumberedEntrySegmenterTest extends TestCase
{
    private function segment(array $pages): array
    {
        return (new NumberedEntrySegmenter)->segment($pages);
    }

    public function test_entries_split_at_their_numbered_headings_and_run_on_across_pages(): void
    {
        $items = $this->segment([
            402 => "وكذا الكفعمي في حواشي \" البلد الأمين \" وقد يعبر عنه كما في\nالرياض وآخر البلد الأمين بتحفة المؤمن .\n\n"
                . "( 1446 : التحفة ) في أصول الفقه للمولى مراد بن علي خان التفريشي\nالمولود سنة 965 والمتوفى سنة 1051 .\n\n"
                . "( 1447 : التحفة ) في الطب ويقال له تحفة المؤمن أو تحفة المؤمنين ،\nللسيد الحكيم الشهير ب\u{200d} \" حكيم مؤمن \" وكثير",
            403 => "من مجرباته ومجربات والده من الأدوية المفردة والمركبة من العقاقير\nطبع بإيران مكررا .\n\n"
                . "( 1448 : التحفة ) أرجوزة في المبدأ والمعاد للسيد مهدي بن علي بن",
        ]);

        // the text before the volume's first heading is its own item
        $this->assertSame(['p402/0', '1446', '1447', '1448'], array_column($items, 'key'));
        $this->assertSame('preface', $items[0]['kind']);
        [$_, $a, $b, $c] = $items;
        $this->assertSame([1446, 'التحفة', 402, 402], [$a['entry_number'], $a['title'], $a['page_start'], $a['page_end']]);
        $this->assertSame([402, 403], [$b['page_start'], $b['page_end']]);
        $this->assertStringContainsString("وكثير\nمن مجرباته", $b['text']);
        $this->assertSame([[mb_strpos($b['text'], "\nمن مجرباته"), 403]], $b['page_breaks']);
        $this->assertSame([403, 403], [$c['page_start'], $c['page_end']]);
        $this->assertSame('تحفه', $a['title_normalized']);
    }

    public function test_an_unnumbered_entry_begins_after_a_finished_sentence_but_not_an_incipit(): void
    {
        $items = $this->segment([
            934 => "( 6151 : ديوان گلشنى بردعي ) إبراهيم بن محمد بن الحاج إبراهيم بن شهاب الدين\n"
                . "في ( دجا ص 318 320 ) وذكر ديوانه في ( كشف الظنون ) .\n"
                . "( ديوان گلشن يزدي ) راجع گلشن اردكانى وگلشن مشهدي .\n\n"
                . "( 6152 : ديوان گلشنى شبسترى ) رأيت النسخة في مكتبتهم بالحسينية أوله\n"
                . "( الحمد لله الذي خلق السماوات والأرض ) مرتب على مقدمة وفصول",
        ]);

        $this->assertSame(['6151', '6151/1', '6152'], array_column($items, 'key'));
        $this->assertSame(['sub_entry', 'ديوان گلشن يزدي', '6151'], [$items[1]['kind'], $items[1]['title'], $items[1]['parent_key']]);
        $this->assertStringContainsString('( الحمد لله الذي خلق', $items[2]['text']);
    }

    public function test_a_footnote_closing_the_page_goes_to_its_entry_and_its_mark_leaves_the_title(): void
    {
        $items = $this->segment([
            4 => "( 20 : الواجب في الاحكام اللواذب ( 1 ) ) لأبي الحسن علي بن الحسين المسعودي\n6 : 82 - 83 ومر له \" اثبات الوصية \" .\n\n"
                . "( 21 : واجب الاعتقاد - الكبير - ) لفخر المحققين محمد بن الحسن بن المطهري\nم\u{200d} 771 .\n\n"
                . "( 1 ) في الفروض اللوازم خ . ل .",
        ]);

        $this->assertSame(['20', '21'], array_column($items, 'key'));
        $this->assertSame('الواجب في الاحكام اللواذب', $items[0]['title']);
        $this->assertSame('واجب الاعتقاد - الكبير -', $items[1]['title']);
        $this->assertSame('( 1 ) في الفروض اللوازم خ . ل .', $items[1]['footnotes']);
        $this->assertStringNotContainsString('الفروض', $items[1]['text']);
    }

    public function test_a_number_printed_twice_or_out_of_order_is_kept_and_flagged(): void
    {
        $items = $this->segment([
            204 => "( 1345 : سفينة النجاة ) فارسي في الأدعية والأعمال .\n\n( 1345 : سفينة النجاح ) في الأدعية .\n\n"
                . "( 1346 : سفينة نظم ونثر ) لبعض المتأخرين .\n\n( 1350 : سقراط ) أصله لآندره كرسون .",
        ]);

        $this->assertSame(['1345', '1345#2', '1346', '1350'], array_column($items, 'key'));
        $this->assertSame(['sequence' => 'repeated', 'previous_number' => 1345], $items[1]['flags']);
        $this->assertSame([], $items[2]['flags']);
        $this->assertSame('gap', $items[3]['flags']['sequence']);
    }

    public function test_a_parenthesised_number_or_section_heading_is_not_an_entry(): void
    {
        $items = $this->segment([
            3 => "( باب الباء الموحدة )\nالبائيات\nقصايد طوال نظمت على روي الباء .",
            4 => "( 610 ) عن ماية وثمان وعشرين سنة ، كما ترجمه وذكر شرحه هذا .\n\n( 4 : البابية ) في الرد عليهم .",
        ]);

        $this->assertSame(['p3/0', '4'], array_column($items, 'key'));
        $this->assertStringContainsString('( 610 ) عن ماية', $items[0]['text']);
        $this->assertSame(4, $items[0]['page_end']);
    }

    public function test_a_number_on_its_own_line_heads_the_entry_after_it_as_in_vol_26(): void
    {
        $items = $this->segment([
            100 => "[ 469 ]\n\n( بزم آرا ) في تذكرة الشعراء ، هو بعينه \" لباب الألباب \" للعوفي .\n\n"
                . "[ 470 ]\n\n( بزم وصال ) من مثنويات وصال الشيرازي ، نقل بعضه في \" مجمع الفصحاء \"\nج 2 ص 541 .",
        ]);

        $this->assertSame(['469', '470'], array_column($items, 'key'));
        $this->assertSame([470, 'بزم وصال', 'entry'], [$items[1]['entry_number'], $items[1]['title'], $items[1]['kind']]);
        $this->assertStringContainsString('نقل بعضه', $items[1]['text']);
    }
}
