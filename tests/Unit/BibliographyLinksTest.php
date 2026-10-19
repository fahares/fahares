<?php

namespace Tests\Unit;

use App\Support\BibliographyLinks;
use Tests\TestCase;

class BibliographyLinksTest extends TestCase
{
    private function links(string $entry): array
    {
        return array_values(array_map(
            fn ($s) => [$s['text'], $s['url']],
            array_filter(BibliographyLinks::segments($entry), fn ($s) => isset($s['url']))
        ));
    }

    public function test_page_volume_citations_of_known_sources_are_linked()
    {
        $this->assertSame([
            ['90/25', 'https://lib.eshia.ir/71602/25/90'],
            ['107/8', 'https://lib.eshia.ir/71602/8/107'],
            ['369/4', 'https://lib.eshia.ir/10984/4/369'],
        ], $this->links('دنا 2/ 207؛ الذریعة 90/25 و 107/8؛ ریحانة الادب 369/4'));
    }

    public function test_spellings_bare_continuations_and_ranges()
    {
        $this->assertSame([
            ['865/9', 'https://lib.eshia.ir/71602/9/865'],
            ['221/2', 'https://lib.eshia.ir/71602/2/221'],
        ], $this->links('الذريعة 865/9؛ 221/2؛ 287/26'));

        $this->assertCount(2, $this->links('الذریعه 509/2 رقم 1998 و 60/20'));
        $this->assertSame([['13/19', 'https://lib.eshia.ir/71602/19/13']], $this->links('ذریعه 13/19-15'));
        $this->assertSame([['417/3', 'https://lib.eshia.ir/10984/3/417']], $this->links('ریحانه 417/3-420'));
    }

    public function test_other_sources_are_not_linked_even_after_a_known_one()
    {
        $this->assertSame([], $this->links('دنا 1177/2؛ فهرستواره منزوی 549/7'));
        $this->assertSame([['865/9', 'https://lib.eshia.ir/71602/9/865']], $this->links('الذریعة 865/9، هدیة العارفین 23/1'));
        $this->assertSame([], $this->links('الذریعة 287/26'));
    }

    public function test_notes_in_parentheses_keep_the_source_and_text_is_preserved()
    {
        $entry = 'الذریعة 11/1 (1نسخه) و 23/2؛ مرقوم پنجم (چاپی)';
        $this->assertCount(2, $this->links($entry));
        $this->assertSame($entry, implode('', array_column(BibliographyLinks::segments($entry), 'text')));
    }

    public function test_html_escapes_text_and_uses_persian_digits()
    {
        $html = (string) BibliographyLinks::html('الذریعة 90/25 <b>نکته</b>');
        $this->assertStringContainsString('&lt;b&gt;نکته&lt;/b&gt;', $html);
        $this->assertStringContainsString('href="https://lib.eshia.ir/71602/25/90"', $html);
        $this->assertStringContainsString('>۹۰/۲۵</a>', $html);
    }

    public function test_dharia_citations_open_the_page_viewer_unless_the_scan_lacks_the_page()
    {
        $viewer = fn (string $entry) => array_values(array_filter(
            array_map(fn ($s) => $s['viewer'] ?? null, BibliographyLinks::segments($entry))
        ));

        $this->assertSame(
            [['catalog' => 'dharia', 'catalogName' => 'الذریعة', 'volume' => 3, 'page' => 402]],
            $viewer('الذریعة 402/3')
        );
        $this->assertSame([], $viewer('الذریعة 98/9'));      // missing from the scan
        $this->assertSame([], $viewer('الذریعة 600/3'));     // past the last page of volume 3

        $html = (string) BibliographyLinks::html('الذریعة 402/3');
        $this->assertStringContainsString('href="https://lib.eshia.ir/71602/3/402"', $html);
        $this->assertStringContainsString("open-catalog-viewer", $html);
        $this->assertStringContainsString('&quot;catalog&quot;:&quot;dharia&quot;', $html);
    }

    public function test_rayhana_citations_open_the_viewer_and_keep_the_link_past_the_scan()
    {
        $viewer = fn (string $entry) => array_values(array_filter(
            array_map(fn ($s) => $s['viewer'] ?? null, BibliographyLinks::segments($entry))
        ));

        $this->assertSame(
            [['catalog' => 'rayhana', 'catalogName' => 'ریحانة الادب', 'volume' => 2, 'page' => 422]],
            $viewer('ریحانة الادب 422/2')
        );
        $this->assertSame([], $viewer('ریحانة الادب 456/2')); // last page of volume 2, not in the scan
        $this->assertSame([], $viewer('ریحانة الادب 404/6'));
        $this->assertSame([], $viewer('ریحانة الادب 3/6'));   // missing from the scan
        $this->assertSame([['456/2', 'https://lib.eshia.ir/10984/2/456'], ['3/6', 'https://lib.eshia.ir/10984/6/3']],
            $this->links('ریحانة الادب 456/2؛ 3/6'));

        $html = (string) BibliographyLinks::html('ریحانه 422/2');
        $this->assertStringContainsString('href="https://lib.eshia.ir/10984/2/422"', $html);
        $this->assertStringContainsString('&quot;catalog&quot;:&quot;rayhana&quot;', $html);
    }

    public function test_kashf_columns_open_the_viewer_and_have_no_link_otherwise()
    {
        $viewer = fn (string $entry) => array_values(array_filter(
            array_map(fn ($s) => $s['viewer'] ?? null, BibliographyLinks::segments($entry))
        ));

        $this->assertSame(
            [
                ['catalog' => 'kashf', 'catalogName' => 'کشف الظنون', 'volume' => 1, 'page' => 500],
                ['catalog' => 'kashf', 'catalogName' => 'کشف الظنون', 'volume' => 2, 'page' => 1342],
            ],
            $viewer('كشف الظنون 500/1 و 1342/2')
        );
        // volume 2 starts at column 945; 23/2 and 1544/1 are not in this edition
        $this->assertSame([], $this->links('کشف الظنون 23/2؛ 1544/1'));

        $html = (string) BibliographyLinks::html('کشف الظنون: 1075/2');
        $this->assertStringContainsString('href="#" title="دیدن برگه‌ی کشف الظنون، ج ۲، ستون ۱۰۷۵"', $html);
        $this->assertStringContainsString('&quot;catalog&quot;:&quot;kashf&quot;', $html);
    }

    public function test_viewer_pages_lists_each_showable_page_once()
    {
        $this->assertSame(
            [
                ['catalog' => 'dharia', 'catalogName' => 'الذریعة', 'volume' => 3, 'page' => 402],
                ['catalog' => 'dharia', 'catalogName' => 'الذریعة', 'volume' => 5, 'page' => 10],
            ],
            BibliographyLinks::viewerPages(['الذریعة 402/3 و 98/9؛ ریحانة الادب 456/2', 'الذریعة 402/3، 10/5'])
        );
    }
}
