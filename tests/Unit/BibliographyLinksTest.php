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
            ['287/26', '#'],
        ], $this->links('الذريعة 865/9؛ 221/2؛ 287/26'));

        $this->assertCount(2, $this->links('الذریعه 509/2 رقم 1998 و 60/20'));
        $this->assertSame([['13/19', 'https://lib.eshia.ir/71602/19/13']], $this->links('ذریعه 13/19-15'));
        $this->assertSame([['417/3', 'https://lib.eshia.ir/10984/3/417']], $this->links('ریحانه 417/3-420'));
    }

    public function test_a_bare_page_after_va_is_in_the_volume_of_the_citation_before_it()
    {
        $this->assertSame([
            ['109/20', 'https://lib.eshia.ir/71602/20/109'],
            ['130/24', 'https://lib.eshia.ir/71602/24/130'],
            ['131', 'https://lib.eshia.ir/71602/24/131'],
            ['162/5', 'https://lib.eshia.ir/71602/5/162'],
            ['317', 'https://lib.eshia.ir/71602/5/317'],
        ], $this->links('الذريعة 109/20 و 130/24 و 131 و 162/5 و 317'));

        $this->assertCount(3, $this->links('الذریعة 130/24 و 131 و 135'));
        $this->assertSame([], $this->links('دنا 2/207 و 208'));                  // not a linked source
        $this->assertCount(1, $this->links('الذریعة 509/2 رقم 1998 و 20'));      // follows a number, not a citation
        $this->assertCount(1, $this->links('الذریعة 509/2؛ 20'));                 // only «و» joins a bare page
        $this->assertCount(1, $this->links('الذریعة 13/19-15 و 20'));
        $this->assertCount(1, $this->links('الذریعة 374/1 و 13: 366'));          // «volume: page», not a page of volume 1
    }

    public function test_other_sources_are_not_linked_even_after_a_known_one()
    {
        $this->assertSame([], $this->links('دنا 1177/2؛ فهرستواره منزوی 549/7'));
        $this->assertSame([['865/9', 'https://lib.eshia.ir/71602/9/865']], $this->links('الذریعة 865/9، الأعلام 23/1'));
        $this->assertSame([], $this->links('الذریعة 287/27'));
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

        // volume 26 (the Mustadrakat) is not on eshia: it opens the viewer and has no eshia link
        $this->assertSame(
            [['catalog' => 'dharia', 'catalogName' => 'الذریعة', 'volume' => 26, 'page' => 287]],
            $viewer('الذریعه 287/26')
        );
        $this->assertSame([], $this->links('الذریعة 408/26'));   // past the last page of volume 26
        $this->assertStringNotContainsString('71602/26', (string) BibliographyLinks::html('الذریعة 287/26'));

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

    public function test_idah_and_hadiyya_columns_open_the_viewer_under_all_their_names()
    {
        $viewer = fn (string $entry) => array_map(
            fn ($v) => [$v['catalog'], $v['volume'], $v['page']],
            array_values(array_filter(array_map(fn ($s) => $s['viewer'] ?? null, BibliographyLinks::segments($entry))))
        );

        $this->assertSame([['idah', 1, 302], ['idah', 2, 23], ['idah', 2, 504]],
            $viewer('ایضاح المكنون 302/1؛ ذیل کشف الظنون 23/2؛ ذیل چلبی 504/2'));
        $this->assertSame([['hadiyya', 1, 406], ['hadiyya', 2, 39], ['hadiyya', 1, 249]],
            $viewer('هدية العارفين 406/1 و 39/2؛ بغدادی، هدیه 249/1'));
        $this->assertSame([], $viewer('ایضاح المکنون 659/1؛ هدیة العارفین 590/2')); // past the last column
        $this->assertSame([['kashf', 1, 500]], $viewer('کشف الظنون 500/1'));
    }

    public function test_kahhala_opens_the_viewer_under_all_its_names_and_forms()
    {
        $viewer = fn (string $entry) => array_map(
            fn ($v) => [$v['catalog'], $v['volume'], $v['page']],
            array_values(array_filter(array_map(fn ($s) => $s['viewer'] ?? null, BibliographyLinks::segments($entry))))
        );

        $this->assertSame([['kahhala', 5, 128], ['kahhala', 3, 105], ['kahhala', 11, 291], ['kahhala', 9, 285]],
            $viewer('معجم المؤلفین 128/5؛ کحاله 105/3؛ معجم المؤلفین، کحاله 291/11؛ معجم المؤلفين 285/9'));
        $this->assertSame([['kahhala', 12, 46], ['kahhala', 6, 162], ['kahhala', 4, 216]],
            $viewer('معجم المولفین 46/12؛ معجم المؤلفين كحالة 162/6؛ معجم المؤلفین عمر رضا کحاله: 216/4'));
        $this->assertSame([['kahhala', 13, 304], ['kahhala', 9, 87], ['kahhala', 11, 196]],
            $viewer('معجم المؤلفین 13/ 304؛ معجم المؤلفين 87/9 و 196/11'));

        // «volume: page» or «page: volume», «volume-page» and «ج/ص» right after the name
        $this->assertSame([['kahhala', 9, 15], ['kahhala', 7, 108], ['kahhala', 11, 314], ['kahhala', 12, 218]],
            $viewer('معجم المؤلفين 9: 15؛ معجم المولفین 108: 7؛ معجم المؤلفين 11-314؛ معجم المؤلفين، ج 12، ص 218-219'));

        // the four-volume Risala edition, other books named alike, pages past the volume
        $this->assertSame([], $viewer('معجم المؤلفین، کحاله (چاپ جدید) 334/3؛ کحاله (چاپ جدید)130/2؛ معجم المؤلفين، کحاله چاپ جدید 346/2'));
        $this->assertSame([], $viewer('معجم المؤلفین العراقیین 120/2؛ تكملة معجم المؤلفين 45/1؛ مستدرک علی معجم المؤلفین 527/1'));
        $this->assertSame([], $viewer('معجم المؤلفین 321/11؛ معجم المؤلفین 779/3'));
        $this->assertSame([['kahhala', 13, 421]], $viewer('کحاله 421/13'));

        // the other sources keep reading a colon or a dash only as before
        $this->assertSame([], $this->links('الذریعة 13-15'));
        $this->assertStringContainsString('href="#" title="دیدن برگه‌ی معجم المؤلفین، ج ۳، ص ۱۰۵"', (string) BibliographyLinks::html('کحاله 105/3'));
    }

    public function test_citations_without_the_page_volume_form()
    {
        $viewer = fn (string $entry) => array_map(
            fn ($v) => [$v['catalog'], $v['volume'], $v['page']],
            array_values(array_filter(array_map(fn ($s) => $s['viewer'] ?? null, BibliographyLinks::segments($entry))))
        );

        // «ج N، ص M» in any source, and a bare «و» page after it
        $this->assertSame([['kashf', 1, 185], ['idah', 2, 669], ['hadiyya', 1, 244]],
            $viewer('کشف الظنون ج 1، ص 185؛ ایضاح المکنون: ج2ص669، الأعلام للزرکلی: ج6ص41؛ هدیة العارفین ج 1-ستون 244'));
        $this->assertSame([['kashf', 1, 195], ['kashf', 1, 196]], $viewer('کشف الظنون ج 1-195 و 196'));
        $this->assertSame([['https://lib.eshia.ir/71602/3/402']], array_map(fn ($l) => [$l[1]], $this->links('الذریعة ج 3، ص 402')));

        // Kashf counts its columns on across both volumes, so a bare column tells its volume
        $this->assertSame([['kashf', 2, 1266]], $viewer('کشف الظنون 1266'));
        $this->assertSame([['kashf', 1, 135], ['kashf', 2, 1855], ['kashf', 2, 1857]],
            $viewer('کشف الظنون (چاپ استانبول) 135؛ کشف الظنون: 1855 و 1857'));
        $this->assertSame([], $viewer('کشف الظنون (100.1 چاپ مصر)'));
        $this->assertSame([], $viewer('ايضاح المكنون: 324'));       // columns restart in each volume of Idah

        // «volume-column» and «volume: column» after the name of a column book
        $this->assertSame([['kashf', 1, 93], ['hadiyya', 2, 268]], $viewer('کشف الظنون 1-93؛ هدیة العارفین 2- 268'));
        $this->assertSame([['kashf', 1, 127]], $viewer('کشف الظنون (چاپ استانبول) 1: 127'));
        $this->assertSame([], $this->links('الذریعة 13-15'));
        $this->assertSame([['15/19', 'https://lib.eshia.ir/71602/19/15']], $this->links('الذریعة 13-15/19'));

        // the Beirut reprint numbers Idah 3-4 and Hadiyya 5-6
        $this->assertSame([['idah', 1, 417], ['hadiyya', 1, 343], ['hadiyya', 2, 369]],
            $viewer('ذیل کشف الظنون 417/3؛ هدية العارفين 343/5؛ هدیة العارفین 369/6'));
        $this->assertSame([], $viewer('هدية العارفين 554/4'));
        $this->assertSame([['hadiyya', 2, 6]], $viewer('هدیة العارفین 2/6')); // own volume first

        // a bare number after «و» belongs to the citation before it, never to a source named earlier
        $this->assertSame([], $viewer('کشف الظنون 72: 2 و 273'));
        $this->assertSame([['kashf', 1, 94]], $viewer('کشف الظنون 1: 94 و 2'));
        $this->assertSame([['kashf', 1, 741], ['kashf', 1, 484]], $viewer('کشف الظنون 741/1 و 1-484'));
        $this->assertCount(1, $this->links('الذریعه 116/13 و 117: نشانی 7'));
        $this->assertSame([['kashf', 1, 557]], $viewer('کشف الظنون ص 557'));
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
