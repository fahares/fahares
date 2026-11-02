<?php

namespace Tests\Unit;

use App\Support\CopyLines;
use PHPUnit\Framework\TestCase;

class CopyLinesTest extends TestCase
{
    public function test_layouts_of_the_catalogues_wordings()
    {
        $cases = [
            'جلد: تیماج، 52گ، 20 سطر، اندازه' => ['20 سطر', [[20, 20]]],
            // two layouts, not a span from 18 to 19
            '316گ، 18 و 19 سطر' => ['18 و 19 سطر', [[18, 18], [19, 19]]],
            '11 و 20 و 24 سطر' => ['11 و 20 و 24 سطر', [[11, 11], [20, 20], [24, 24]]],
            '19، 22 و 25 سطر' => ['19، 22 و 25 سطر', [[19, 19], [22, 22], [25, 25]]],
            // one layout whose count varies
            '380گ، 15-22 سطر (11/5×15)' => ['15-22 سطر', [[15, 22]]],
            '18تا 20 سطر' => ['18تا 20 سطر', [[18, 20]]],
            '17-15-10 سطر' => ['17-15-10 سطر', [[17, 17], [15, 15], [10, 10]]],
            // a leaf or page number the run caught is not a count
            '(487-521)، 25 سطر' => ['25 سطر', [[25, 25]]],
            '133، 21 سطر' => ['21 سطر', [[21, 21]]],
            // nor is the end of the text block's size
            '241گ، 25 سطر 13/5×23 سطر، اندازه' => ['25 سطر', [[25, 25]]],
            '۲۱ سطر' => ['21 سطر', [[21, 21]]],
            '31  <!-- page: 414 --> سطر' => ['31 سطر', [[31, 31]]],
            // a fraction keeps its wording but gives no count
            '14ص، 12/5 سطر در حواشی' => ['12/5 سطر', null],
        ];
        foreach ($cases as $text => [$raw, $counts]) {
            $this->assertSame(['lines_raw' => $raw, 'line_counts' => $counts], CopyLines::fromText($text), $text);
        }
        $this->assertNull(CopyLines::fromText('شرح میان سطرها؛ 30 بیت'));
    }

    public function test_columns_are_written_only_where_they_differ()
    {
        $row = (object) ['raw_text' => '18 و 21 سطر', 'lines_raw' => '18 و 21 سطر', 'line_counts' => '[[18,18],[21,21]]'];
        $this->assertNull(CopyLines::columns($row));
        $row->raw_text = '20 سطر';
        $this->assertSame(['lines_raw' => '20 سطر', 'line_counts' => '[[20,20]]'], CopyLines::forUpdate(CopyLines::columns($row)));
    }
}
