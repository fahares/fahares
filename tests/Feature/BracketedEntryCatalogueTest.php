<?php

namespace Tests\Feature;

use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Sources\Extractors\BracketedEntryCatalogueExtractor;
use App\Sources\Extractors\Extractors;
use Tests\TestCase;

/** The Sepahsalar catalogue's second edition, as Ghaemiyeh's reader gives it (step 14.267). */
class BracketedEntryCatalogueTest extends TestCase
{
    /** Vol. 3, pp. 2-5, shortened: the basmala page ends with no blank line; the series number runs into the shelfmark. */
    private const VOL3 = [
        2 => "بسم اللّه الرحمن الرحيم\n",
        3 => "[1] آبستنى وباه پزشكى/ فارسى\nرساله بيست در يك مقدمه و سه مقالت.\nآغاز: حمد بى حد خالقى را...\n12931: ش 49 دفتر. در 20 برگ متن. جنگ شماره 2931 در همين فهرست ديده شود.\n\n[2] آب نيسان و قمر در عقرب ستاره‌شناسى/ فارسى\nنگارش محمد باقر خاتون آبادى.\n21844: ش 2 دفتر. 9 پ- 13 ر.\n\n[3] آتشكده تاريخ شعرا/ فارسى\nتذكره حال شاعران فارسى زبان.\n",
        4 => "انجام: ... آذر.\n3 2674: شكسته نستعليق على اصغر در 1207 ق. عنوانها گاهى زر و گاهى\n",
        5 => "شنگرف. 340 گ.\n4 2676: شكسته نستعليق محمد رضا كاتب.\nدر 1263 ق. نوشته شده است.\n5 2682: نستعليق 1229 ق.\n",
    ];

    /** Vol. 4 (p. 10) and vol. 5 (p. 100), shortened: a work's paragraph, its copies' paragraphs, a copy inside a work's. */
    private const VOL4 = [
        10 => "جهانگشاى نادرى\n(1026) جهانگشاى نادرى (تاريخ. فارسى) از ميرزا مهدى استرآبادى منشى نادرشاه.\n\nديوان مختارى غزنوى (2)\n374 نستعليق سده دهم. آغاز و پايان افتاده.\n",
        11 => "جلد تيماج. 109 گ.\n\nخسرو شيرين (1)\n355 ش 2 دفتر است.) سپه 536: 2) ش 1 \" مخزن الاسرار \" است.\n\nجهانگشاى نادرى (1)\n1200: نسخ سده دوازدهم.\n\nمفردات طبى\nمفردات طبى: داروهاى ساده بزبان عربى و فارسى. 2925: ش 1 دفتر، ص 11- 1: عنوان شنگرف.\n",
    ];

    /** Vol. 2 (Ibn Yusuf), shortened: a work «[589]», a copy «(892)» closing with its shelfmark, a footnote mark in place of one. */
    private const VOL2 = [
        20 => "مبانى اللغه\n[430] مبانى اللغه (تركى) از ميرزا مهدى خان. (892) مبانى اللغه يا مقدمه سنگلاخ اين نسخه بخط شكسته نستعليق. طول (30 سانتي متر). شماره كتابخانه (2726). (893) نسخه ديگر شماره كتابخانه (1) {1} _______.\n",
    ];

    public function test_vol_3_reads_entries_copy_lines_and_run_together_series_numbers()
    {
        [$index, $entries, $lines] = (new BracketedEntryCatalogueExtractor)->index(self::VOL3);
        $this->assertSame(4, $entries, 'the basmala, and three entries: [1] opens its own though page 2 has no blank line at its end');
        $jung = $index['2931'][0];
        $this->assertSame([3, 1, 49], [$jung['page'], $jung['entry']['number'], $jung['part']]);
        $this->assertSame(3, $jung['entry']['page_start']);
        $this->assertStringStartsWith('ابستنی وباه پزشکی=ابستنی وباه', $jung['entry']['title'], 'the title in its forms, the subject dropped');
        [$entry, $copy] = $jung['texts'];
        $this->assertStringStartsWith('[1] آبستنى وباه', $entry);
        $this->assertStringNotContainsString('2931', $entry);
        $this->assertStringStartsWith('12931: ش 49 دفتر', $copy);
        $this->assertSame([2], [$index['1844'][0]['part']]);
        // a copy's line runs on over the page, and the next copy closes it
        $this->assertSame("3 2674: شكسته نستعليق على اصغر در 1207 ق. عنوانها گاهى زر و گاهى شنگرف. 340 گ.", $index['2674'][0]['texts'][1]);
        $this->assertSame([3, 5], [$index['2676'][0]['entry']['page_start'], $index['2676'][0]['entry']['page_end']]);
        $this->assertSame("4 2676: شكسته نستعليق محمد رضا كاتب.\nدر 1263 ق. نوشته شده است.", $index['2676'][0]['texts'][1]);
        $this->assertArrayNotHasKey('1263', $index, 'a number inside a copy\'s description is not a copy');

        $this->assertSame([1, '2931', 'ش 49 دفتر.'], BracketedEntryCatalogueExtractor::copyLine('12931: ش 49 دفتر.', 0));
        $this->assertSame([14, '1844', 'ش 3'], BracketedEntryCatalogueExtractor::copyLine('14 1844: ش 3', 13));
        $this->assertNull(BracketedEntryCatalogueExtractor::copyLine('1263: سال', 13));
        $this->assertSame('آتشكده تاريخ شعرا=آتشكده تاريخ=آتشكده', BracketedEntryCatalogueExtractor::titles('آتشكده تاريخ شعرا/ فارسى'));
    }

    public function test_vols_4_and_5_read_copy_paragraphs_with_their_works_paragraph()
    {
        [$index] = (new BracketedEntryCatalogueExtractor)->index(self::VOL4);
        $nadiri = $index['1200'][0];
        $this->assertSame(11, $nadiri['page']);
        $this->assertStringStartsWith('جهانگشاى نادرى', $nadiri['texts'][0]);
        $this->assertStringContainsString('(1026) جهانگشاى نادرى (تاريخ. فارسى)', $nadiri['texts'][0], "the work's paragraph is the entry text");
        $this->assertSame("جهانگشاى نادرى (1)\n1200: نسخ سده دوازدهم.", $nadiri['texts'][1]);
        $this->assertSame("ديوان مختارى غزنوى (2)\n374 نستعليق سده دهم. آغاز و پايان افتاده.\nجلد تيماج. 109 گ.", $index['374'][0]['texts'][1]);
        $this->assertSame(2, $index['355'][0]['part']);
        $inline = $index['2925'][0];
        $this->assertSame(1, $inline['part']);
        $this->assertStringStartsWith('2925: ش 1 دفتر', $inline['texts'][1]);
        $this->assertStringNotContainsString('2925', $inline['texts'][0]);
        $this->assertArrayNotHasKey('536', $index, '«سپه 536: 2» cites the first printing');
    }

    public function test_vols_1_and_2_read_the_library_number_closing_a_copy()
    {
        [$index] = (new BracketedEntryCatalogueExtractor)->index(self::VOL2);
        [$entry, $copy] = $index['2726'][0]['texts'];
        $this->assertStringStartsWith('(892) مبانى اللغه يا مقدمه سنگلاخ', $copy);
        $this->assertStringEndsWith('شماره كتابخانه (2726)', $copy);
        $this->assertStringContainsString('[430] مبانى اللغه (تركى)', $entry);
        $this->assertSame(430, $index['2726'][0]['entry']['number']);
        $this->assertArrayNotHasKey('1', $index, 'a footnote mark is not a shelfmark');
    }

    public function test_match_edition_reads_the_edition_in_its_sources_format_and_the_extractor_takes_the_same_text()
    {
        $library = Library::create(['name' => 'سپهسالار', 'city' => 'تهران', 'slug' => 'sepahsalar-' . uniqid()]);
        $cited = CatalogVolume::create(['title' => 'فهرست کتابخانه مدرسه عالی سپهسالار - جلد سوم', 'volume_number' => 3, 'library_id' => $library->id]);
        $edition = CatalogVolume::create(['title' => 'فهرست نسخه‌های خطی مدرسۀ عالی شهید مطهری - جلد سوم', 'volume_number' => 3, 'library_id' => $library->id,
            'edition_of_id' => $cited->id, 'facsimile_code' => 'sepahsalar-test', 'edition_label' => 'دوم']);
        $volume = SourceVolume::findOrFail($edition->id);
        $copy = function (string $shelfmark, string $title) use ($library) {
            $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'w-sep-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

            return Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => $shelfmark, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1]);
        };
        $abestani = $copy('2931/49', 'آبستنی و باه');
        $atashkade = $copy('2676', 'آتشکده');
        $path = tempnam(sys_get_temp_dir(), 'pages') . '.jsonl';
        file_put_contents($path, implode("\n", array_map(fn ($p, $t) => json_encode(['page' => $p, 'text' => $t], JSON_UNESCAPED_UNICODE), array_keys(self::VOL3), self::VOL3)));

        $this->artisan('sources:import-pages', ['volume' => 'sepahsalar-test', 'pages' => $path, '--extractor' => 'bracketed_entries'])->assertSuccessful();
        $this->artisan('catalogs:match-edition', ['edition' => 'sepahsalar-test', 'pages' => $path])->assertSuccessful();
        unlink($path);

        $refs = SourceEntry::where('source_volume_id', $volume->id)->get()->keyBy('citable_id');
        $this->assertSame([3, 3, 1], [$refs[$abestani->id]->page_start, $refs[$abestani->id]->shelfmark_page, $refs[$abestani->id]->entry_number]);
        $this->assertSame([3, 5, 3], [$refs[$atashkade->id]->page_start, $refs[$atashkade->id]->shelfmark_page, $refs[$atashkade->id]->entry_number]);
        $this->assertStringStartsWith('4 2676:', $refs[$atashkade->id]->copy_text);
        foreach ($refs as $ref) {
            $taken = Extractors::extract($ref);
            $this->assertSame([$ref->entry_text, $ref->copy_text], [$taken['entry_text'], $taken['copy_text']]);
        }
    }
}
