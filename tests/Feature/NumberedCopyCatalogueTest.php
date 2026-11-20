<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Sources\Extractors\Extractors;
use App\Sources\Extractors\NumberedCopyCatalogueExtractor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Golpaygani catalogue's entries and copies (step 14.206), read from its page texts as step 14.200 made them. */
class NumberedCopyCatalogueTest extends TestCase
{
    /**
     * Pages 1495-1496 of vol. 3, shortened: a copy whose description the page broke, one whose description the text
     * layer wrapped after its leaves, a cross reference, and the end of a reference list put after the next copy.
     */
    private const PAGES = [
        1495 => "حاشیة شرح العوامل المأة (نحو- عربی)\nحریری کروی ، احمد بن محمد\nحاشیه مفصلی است بر(شرح عوامل مأة) سعدالدین بردعی با عنوانهای (قوله- قوله)\n"
            . "۴۵۲۳) نسخه شماره: ۲۶۰۸/۳-۱۴/۵۸\nنسخ، محمد حسینی، ۲۰رجب۱۳۰۹، درقریه قهرآباد، جلد:گالینگور قهوه ای، ۵۹برگ، ۱۳ سطر ، ۱۸×۲۳سم\n"
            . "۱۳/۱۸ فهرست دانشگاه تهران ۳۲۴۱/۱۳]\nحاشیه شرح ایساغوجی ← حاشیة شرح الفناری\n"
            . "حاشیة شرح الفناری علی الایساغوجی = حاشیة شرح ایساغوجی = حاشیة قول\nاحمد بن محمد بن خضر، - ۷۸۵ ق (منطق- عربی)\n"
            . "حاشیه ایست با عناوین (قوله - قوله) بر (الفوائد الفناریة) شمس الدین محمد بن حمزه فناری\n[کشف الظنون ۲۰۷/۱؛ فهرست آستان قدس ۴۶۲/۱۱]\n"
            . "۴۵۲۴) نسخه شماره: ۱۱۶۵/۱-۷/۶۵\nآغاز:بسمله الحمدلله الذی جعل المنطق میزانا\nانجام:وهذا آخر ما اردنا ایراده\n",
        1496 => "نستعلیق، محمود، ۱۲۴۷، عناوین ونشانیها شنگرف، با حواشی بسیاری از جمله حاشیه مولوی عبدالحکیم بر خیال، بدون جلد، ۱۳برگ، ۲۴-۲۵ سطر ، ۱۵/۵×۲۳سم\n"
            . "۴۵۲۵) نسخه شماره: ۱۷۵۶/۵-۱۰/۶۶\nاز اول تاآخر، نسخ، عبدالخالق، ۱۳۱۴، جلد:مقوا، ۴۴برگ، ۱۲ سطر ، ۱۷×۲۲سم\n"
            . "حاشیة شرح الکافی (شرح حدیث- عربی)\nنوری ، علی بن جمشید، - ۱۲۴۶ ق\nحاشیه ای است بر (شرح اصول کافی) ملاصدرا.\n[فهرست مجلس ۴۳/۴]\n"
            . "۴۵۲۸) نسخه شماره: ۱۲۳۳/۳-۷/۱۳۳\nآغاز:بسمله لان منشأ الافتقار بل الافتقار حسبما حقق\nانجام:وربما اشتملت علیها من صور أعیان الجزئیات\n"
            . "نسخ، بی کا، جای عناوین سفید مانده، نسخه در حاشیه تصحیح شده، جلد: تیماج، قهوه ای، ۱۸برگ،\n۱۷- ۱۸ سطر ، ۱۵/۵×۲۱سم\n"
            . "حاشیة شرح الکافیة = حاشیة الفوائد الضیائیة = حاشیه شرح جامی (نحو- عربی)\nجزایری ، نعمت الله بن عبدالله، ۱۰۵۰ - ۱۱۱۲ ق\n",
    ];

    /** Pages 4091-4092 of vol. 7, shortened: the composite volume 8100 and the next one. */
    private const COMPOSITE = [
        4091 => "مجموعه (گوناگون- عربی و ترکی)\n۱- رسالة فی المنطق (ص ۱ - ۲۰) عربی\nآغاز: بسمله الحمد لله\n۸- تهلیلیه(ص ۲۰۱-۲۱۰ ) اعتقادات - عربی\nآغاز : الحمد لله الذی بین وحدانیته\n",
        4092 => "۹- رسالة فی العقاید (ص ۲۸۰ - ۲۸۴ ) عربی\nآغاز : بسمله ؛ قال الاستاذ قدس سره : اعلم ان الشیخ ابا الحسن الاشعری\n"
            . "۱۰- رسالة فی کلمة التوحید (ص ۲۸۷ - ۲۸۹ ) عربی\nآغاز : بسمله ، موحدا لله تعالی\n۱۲۷۷۹) نسخه شماره: ۸۱۰۰-۵۵/۷۰\nبی کا، بی تا، بدون جلد\n"
            . "مجموعه\n۱- حاشیة معالم الدین (گ ۱- ۹) از : ملا میرزا محمدبن حسن شیروانی\n۲- تنبیه اللبیب فی شرح التهذیب (گ ۱۰ - ۲۲۰ )\n"
            . "۱۲۷۸۰) نسخه شماره: ۸۱۱۹-۵۵/۸۹\nبی کا، بی تا، بدون جلد، ۲۲۰برگ، ۱۸ - ۱۹ سطر ، ۱۷×۲۰سم\n",
    ];

    private function extract(array $pages, string $shelfmark, int $page, array $hints = []): ?array
    {
        return (new NumberedCopyCatalogueExtractor)->extract($pages, $shelfmark, $page, $hints);
    }

    public function test_a_copy_is_read_with_its_entry_across_the_page()
    {
        // Fankha writes the numbers in another order; the copy's description begins on the next page
        $found = $this->extract(self::PAGES, '7/65-1165/1', 1495);
        $this->assertSame(4524, $found['entry_number']);
        $this->assertSame([1495, 1496, 1495], [$found['page_start'], $found['page_end'], $found['shelfmark_page']]);
        $this->assertSame("حاشیة شرح الفناری علی الایساغوجی = حاشیة شرح ایساغوجی = حاشیة قول\nاحمد بن محمد بن خضر، - ۷۸۵ ق (منطق- عربی)\n"
            . "حاشیه ایست با عناوین (قوله - قوله) بر (الفوائد الفناریة) شمس الدین محمد بن حمزه فناری\n[کشف الظنون ۲۰۷/۱؛ فهرست آستان قدس ۴۶۲/۱۱]", $found['entry_text']);
        $this->assertStringStartsWith('۴۵۲۴) نسخه شماره: ۱۱۶۵/۱-۷/۶۵', $found['copy_text']);
        // the page that holds its description holds the copy too
        $this->assertStringEndsWith("انجام:وهذا آخر ما اردنا ایراده\nنستعلیق، محمود، ۱۲۴۷، عناوین ونشانیها شنگرف، با حواشی بسیاری از جمله حاشیه مولوی عبدالحکیم بر خیال، بدون جلد، ۱۳برگ، ۲۴-۲۵ سطر ، ۱۵/۵×۲۳سم", $found['copy_text']);
        $this->assertSame(4524, $this->extract(self::PAGES, '1165/1-7/65', 1496)['entry_number']);
        // the cross reference and the end of a reference list the text layer misplaced belong to no entry or copy
        $this->assertStringNotContainsString('←', $found['copy_text'] . $found['entry_text']);
        $this->assertStringEndsWith('۱۸×۲۳سم', $this->extract(self::PAGES, '2608/3-14/58', 1495)['copy_text']);

        // the next copy of the same work, and a work that starts after a copy whose description the text layer wrapped
        $this->assertSame($found['entry_text'], $this->extract(self::PAGES, '1756/5-10/66', 1496)['entry_text']);
        $wrapped = $this->extract(self::PAGES, '1233/3-7/133', 1496);
        $this->assertSame("حاشیة شرح الکافی (شرح حدیث- عربی)\nنوری ، علی بن جمشید، - ۱۲۴۶ ق\nحاشیه ای است بر (شرح اصول کافی) ملاصدرا.\n[فهرست مجلس ۴۳/۴]", $wrapped['entry_text']);
        $this->assertStringEndsWith("۱۸برگ،\n۱۷- ۱۸ سطر ، ۱۵/۵×۲۱سم", $wrapped['copy_text']);
        $this->assertSame(1496, $wrapped['page_start']);
        $this->assertNull($wrapped['page_end']);

        // a page the copy is not on, and a shelfmark the book does not have
        $this->assertNull($this->extract(self::PAGES, '2608/3-14/58', 1496));
        $this->assertNull($this->extract(self::PAGES, '9999-1/1', 1495));
    }

    public function test_a_misprinted_number_is_taken_only_where_nothing_else_fits()
    {
        // «1165/1-7/56»: one number differs from the one copy on the page
        $this->assertSame(4524, $this->extract(self::PAGES, '1165/1-7/56', 1495)['entry_number']);
        // not for a shelfmark a reviewer corrected, nor where the very numbers stand a few pages away
        $this->assertNull($this->extract(self::PAGES, '1165/1-7/56', 1495, ['strict_part' => true]));
        $pages = self::PAGES + [1499 => "حاشیة الف (نحو- عربی)\n۴۵۴۰) نسخه شماره: ۱۱۶۵/۱-۷/۵۶\nنسخ، بی کا، ۱۰برگ، ۱۵ سطر ، ۱۵×۲۱سم\n"];
        ksort($pages);
        $this->assertNull($this->extract($pages, '1165/1-7/56', 1495));
        $this->assertSame(4540, $this->extract($pages, '1165/1-7/56', 1499)['entry_number']);
    }

    public function test_a_treatise_of_a_composite_volume_is_read_by_its_number()
    {
        $found = $this->extract(self::COMPOSITE, '8100/9-55/70', 4092);
        $this->assertSame("مجموعه (گوناگون- عربی و ترکی)\n۹- رسالة فی العقاید (ص ۲۸۰ - ۲۸۴ ) عربی\nآغاز : بسمله ؛ قال الاستاذ قدس سره : اعلم ان الشیخ ابا الحسن الاشعری", $found['entry_text']);
        $this->assertSame("۱۲۷۷۹) نسخه شماره: ۸۱۰۰-۵۵/۷۰\nبی کا، بی تا، بدون جلد", $found['copy_text']);
        $this->assertSame([4091, 4092, 4092, 12779], [$found['page_start'], $found['page_end'], $found['shelfmark_page'], $found['entry_number']]);
        // the volume itself gives all its treatises, from either of its pages
        $whole = $this->extract(self::COMPOSITE, '8100-55/70', 4091);
        $this->assertStringContainsString('۱- رسالة فی المنطق', $whole['entry_text']);
        $this->assertStringEndsWith('آغاز : بسمله ، موحدا لله تعالی', $whole['entry_text']);
        // a composite volume without a subject heads the next entry, and ends the copy before it
        $this->assertSame("مجموعه\n۱- حاشیة معالم الدین (گ ۱- ۹) از : ملا میرزا محمدبن حسن شیروانی\n۲- تنبیه اللبیب فی شرح التهذیب (گ ۱۰ - ۲۲۰ )",
            $this->extract(self::COMPOSITE, '8119-55/89', 4092)['entry_text']);
    }

    public function test_the_command_takes_the_texts_a_source_has_none_of_and_the_page_marks_them()
    {
        $library = Library::create(['name' => 'گلپایگانی', 'city' => 'قم', 'slug' => 'golpaygani-' . uniqid()]);
        $source = Source::create(['code' => 'catalogue-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10, 'title' => 'فهرست گلپایگانی', 'library_id' => $library->id]);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 3, 'facsimile_code' => 'golpaygani-test', 'page_first' => 1101, 'page_last' => 1782]);
        // a work printed with many copies: the last stands eight pages after its heading
        $pages = self::PAGES + [1480 => "شرح اللمعة (فقه- عربی)\nشهید ثانی ، زین الدین بن علی\n۴۴۰۰) نسخه شماره: ۱۰-۱/۱۰\nنسخ، بی کا، ۱۰برگ، ۱۵ سطر ، ۱۵×۲۱سم\n"];
        foreach (range(1481, 1488) as $i => $page) {
            $pages[$page] = sprintf("۴۴%02d) نسخه شماره: %d-۱/%d\nنسخ، بی کا، ۱۰برگ، ۱۵ سطر ، ۱۵×۲۱سم\n", $i + 1, 20 + $i, 20 + $i);
        }
        ksort($pages);
        foreach ($pages as $page => $text) {
            DB::table('source_pages')->insert(['source_volume_id' => $volume->id, 'page' => $page, 'text' => $text]);
        }
        $cite = function (string $shelfmark, ?int $page, array $values = []) use ($library, $source, $volume) {
            $work = Work::create(['primary_title' => 'اثر ' . $shelfmark, 'clean_title' => 'اثر ' . $shelfmark, 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
            $copy = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => $shelfmark, 'volume_number' => 1, 'page_start' => 1]);

            return SourceEntry::create($values + ['citable_type' => Manuscript::class, 'citable_id' => $copy->id, 'source_id' => $source->id, 'source_volume_id' => $volume->id,
                'volume_number' => 3, 'page_start' => $page, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_FANKHA_CITATION,
                'status' => SourceEntry::STATUS_UNVERIFIED, 'citation_text' => "[ف: 3-{$page}]"]);
        };
        $broken = $cite('1165/1-7/65', 1496); // Fankha cites the page its description runs onto
        $far = $cite('27-1/27', 1488);
        $misprint = $cite('2608/3-14/85', 1495);
        $missing = $cite('9999-1/1', 1495);
        $edited = $cite('1233/3-7/133', 1496, ['entry_text' => 'متن داور', 'text_status' => SourceEntry::TEXT_EDITED]);

        $this->artisan('sources:take-texts', ['source' => $source->code, '--extractor' => 'nonesuch'])->assertFailed();
        $this->artisan('sources:take-texts', ['source' => $source->id, '--extractor' => 'numbered_copies', '--dry-run' => true])->assertSuccessful();
        $this->assertNull($source->fresh()->extractor);
        $this->assertSame(SourceEntry::TEXT_NONE, $broken->fresh()->text_status);

        $this->artisan('sources:take-texts', ['source' => $source->code, '--extractor' => 'numbered_copies'])
            ->expectsTable(['', 'count'], [['without text', '4'], ['no page or pages', '0'], ['not found', '1'], ['taken', '3'], ['page from the citation', '0'], ['by a misprint', '1'], ['address changed', '2']])
            ->assertSuccessful();
        $this->assertSame('numbered_copies', $source->fresh()->extractor);
        $broken->refresh();
        $this->assertSame([SourceEntry::TEXT_AUTO, 1495, 1496, 1495, 4524, 1495], [$broken->text_status, $broken->page_start, $broken->page_end, $broken->shelfmark_page, $broken->entry_number, $broken->text_page]);
        $this->assertStringStartsWith('حاشیة شرح الفناری', $broken->entry_text);
        $this->assertSame([1480, 1488, 4408], [$far->fresh()->page_start, $far->fresh()->shelfmark_page, $far->fresh()->entry_number]);
        $this->assertSame(4523, $misprint->fresh()->entry_number);
        $this->assertSame(SourceEntry::TEXT_NONE, $missing->fresh()->text_status);
        $this->assertSame('متن داور', $edited->fresh()->entry_text);
        // each change rolls back from the audit log
        $log = AuditLog::where('auditable_type', SourceEntry::class)->where('auditable_id', $broken->id)->latest('id')->firstOrFail();
        $this->assertSame([1496, 'none'], [$log->old_values['page_start'], $log->old_values['text_status']]);
        $this->assertSame('sources:take-texts', $log->new_values['command']);

        // a second run has nothing to take
        $this->artisan('sources:take-texts', ['source' => $source->code])
            ->expectsTable(['', 'count'], [['without text', '1'], ['no page or pages', '0'], ['not found', '1'], ['taken', '0'], ['page from the citation', '0'], ['by a misprint', '0'], ['address changed', '0']])
            ->assertSuccessful();

        // the page view links the copy eight pages after its work's heading, and the heading where it stands
        Extractors::forget();
        $this->get("/sources/{$source->code}/3/page/1488")->assertOk()
            ->assertSee('<a href="' . route('manuscripts.show', $far->citable) . '" class="book-sheet__mark book-sheet__mark--manuscript"', false);
        $this->get("/sources/{$source->code}/3/page/1495")->assertOk()->assertSee('آثار این صفحه', false)
            ->assertSee('<a href="' . route('works.show', $broken->citable->work) . '" class="book-sheet__mark book-sheet__mark--work"', false);
    }
}
