<?php

namespace Tests\Feature;

use App\Models\Source;
use Tests\TestCase;

/** Step 14.159: «راهنمای نشانه‌ها», the signs a source prints its entries with, under that source's text */
class SourceLegendTest extends TestCase
{
    private const LINE = 'خط: نسخ؛ بی‌کا، تا: 1251ق؛ 5گ (208ر-213ر)، 23 سطر، اندازه: 15×21سم [محدث ارموی ف: 1-27]';

    private function signs(?array $legend): array
    {
        return array_column($legend['items'] ?? [], 'sign');
    }

    public function test_a_text_gets_only_the_signs_it_has()
    {
        $fankha = new Source(['code' => 'fankha', 'title' => 'فنخا']);

        $this->assertSame(['بی‌کا', 'تا', 'گ', 'ر', 'اندازه', 'سم', 'ف'], $this->signs($fankha->legend(self::LINE)));

        // «کا» is not found in «بی‌کا», nor «تا» in «بی‌تا» or in a word; a one-letter sign needs its number
        $this->assertSame(['کا', 'بی‌تا'], $this->signs($fankha->legend('خط: نستعلیق؛ کا: رضا کاتب، بی‌تا؛ گرفتار تا پایان')));
        $this->assertSame(['غیر همانند'], $this->signs($fankha->legend('غیر همانند')));
        $this->assertSame(['همانند'], $this->signs($fankha->legend('(همانند)')));

        // a text with none of them, and a source with no list, have no legend
        $this->assertNull($fankha->legend('متنی ساده'));
        $this->assertNull((new Source(['code' => 'catalogue-none']))->legend(self::LINE));
        $this->assertGreaterThan(20, count($fankha->legend()['items']));
    }

    public function test_the_sources_own_list_in_its_metadata_comes_first()
    {
        $source = new Source(['code' => 'fankha', 'metadata' => ['legend' => ['items' => [['sign' => 'نش', 'meaning' => 'نشانه']]]]]);

        $this->assertSame(['نش'], $this->signs($source->legend()));
    }

    public function test_the_legend_is_a_closed_block_that_names_its_origin()
    {
        $fankha = new Source(['code' => 'fankha', 'title' => 'فنخا']);

        $html = (string) $this->blade('<x-source.legend :source="$source" :text="$text" />', ['source' => $fankha, 'text' => self::LINE]);

        $this->assertMatchesRegularExpression('/<details(?![^>]* open)[^>]*data-source-legend="fankha"/u', $html);
        $this->assertStringContainsString('راهنمای نشانه‌های این متن', $html);
        $this->assertStringContainsString('(۷)', $html);
        $this->assertStringContainsString('بدون کاتب', $html);
        $this->assertStringContainsString('برگرفته از فنخا، «رموز و اختصارات»', $html);
        $this->assertStringNotContainsString('نگاه کنید', $html);

        $this->assertSame('', trim((string) $this->blade('<x-source.legend :source="$source" text="متنی ساده" />', ['source' => $fankha])));
    }
}
