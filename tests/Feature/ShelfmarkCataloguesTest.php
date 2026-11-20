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
use App\Sources\Extractors\ListedShelfmarkCatalogueExtractor;
use App\Sources\Extractors\ShelfmarkHeadedCatalogueExtractor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Catalogues read by their copies' shelfmarks (backlog item 147): the Majlis catalogue's headings and Marvi's lists. */
class ShelfmarkCataloguesTest extends TestCase
{
    /** Pages 119-121 of Majlis vol. 20, shortened: a copy, a composite volume run onto the next page, and the next copy. */
    private const MAJLIS = [
        119 => "۶۵۴۶\nشرح تجرید (کلام ـ عربی)\nخط: نسخ.\n(ثبت ۶۱۹۶۴)\n۶۵۴۷\nهیئت = فتحیه (هیئت ـ فارسی)\nاز: علاءالدین علی بن محمّد قوشچی (۸۷۹ ه).\n"
            . "نک: ش ۶/ ۶۴۵۵.\nخط: نستعلیق. تاریخ کتابت: ۱۰۱۰ه .\nجلد: تیماج قهوه‌ای.\n۹/۵ × ۱۶/۵ سم. ۴۸ گ. ۱۶ س.\n(ثبت ۴۹۲۸۸)\n",
        120 => "۶۵۴۸\nمجموعه\n۱. القصیدة الدالیة (شعر، ادب ـ عربی)\n(۱ پ ـ ۱۳ ر)\nاز: مرتضی قلی خان.\n۲. صغری (منطق ـ فارسی)\n(۱۴ پ ـ ۱۸ پ)\n"
            . "خط خوش در این رساله.\n۳. الایساغوجی (منطق ـ عربی)\n(۱۹ ر ـ ۲۴ پ)\nاز: اثیرالدین ابهری (۶۶۳ه).\n",
        121 => "خط: رسالۀ اول نسخ معرب زیبا. کاتب: مهدی بن شیخ علی.\nجلد: تیماج.\n۱۰ × ۱۵ سم. ۱۰۴ گ.\n(ثبت ۵۰۱۲۳)\n۷۰\n(۶۵۴۹)\nمجموعه:\n۱. [ادعیه] «۱ پ ـ ۱۸ پ» (دعا ـ عربی)\n",
    ];

    /** Pages 262 and 290 of the Marvi catalogue: a work with two copies, footnotes, and composite volumes. */
    private const MARVI = [
        262 => "( ش ۲۱۱ )\nنهایة المرام ( فقه ، عربی )\n* ش ۲۱۱ ، رحلی ، تحریر ۱۲۲۶ .\n( ش ۳۹۱ و۳۹۴ )\nنهج البلاغة ( حدیث ، عربی )\n"
            . "گردآوری سیدرضی متوفای ۴۰۶ .\n* ش ۳۹۱ ، وزیری ، تحریر ۱۱۰۷ .\n* ش ۳۹۴ ، وزیری ، تحریر سده ۱۱ و۱۲ .\n---\n(۱) ش ۳۹۴ در فهرست دیگر.",
        263 => "( ش ۰۰۰[؟] )\nبحارالانوار (حدیث ،عربی)\n* ش ۹۳ ،رحلی،جلد اول ودوم .\n* ش ۱۲۴ رحلی ، جلد یازدهم .\n( ش ۹۶۳ )\nبه خط نسخ ، تاریخ ۱۲۳۱ .\n",
        290 => "( ش ۶۵۱ - خشتی )\n۱ - اکسیرالعارفین ملاصدرا ، تحریر ۱۲۷۹ .\n۲ - رسالة فی خلق الاعمال از ملا صدرا ، تحریر ۱۲۷۹ .\n( ش۶۵۷ - خشتی )\n۱ - خرائج راوندی ، تحریر ۱۲۷۱ .\n",
    ];

    private function majlis(string $shelfmark, int $page, array $hints = []): ?array
    {
        return (new ShelfmarkHeadedCatalogueExtractor)->extract(self::MAJLIS, $shelfmark, $page, $hints);
    }

    private function marvi(string $shelfmark, int $page): ?array
    {
        return (new ListedShelfmarkCatalogueExtractor)->extract(self::MARVI, $shelfmark, $page);
    }

    public function test_a_majlis_copy_is_its_heading_and_its_description()
    {
        $found = $this->majlis('6547', 119);
        $this->assertSame("۶۵۴۷\nهیئت = فتحیه (هیئت ـ فارسی)\nاز: علاءالدین علی بن محمّد قوشچی (۸۷۹ ه).\nنک: ش ۶/ ۶۴۵۵.", $found['entry_text']);
        $this->assertSame("خط: نستعلیق. تاریخ کتابت: ۱۰۱۰ه .\nجلد: تیماج قهوه‌ای.\n۹/۵ × ۱۶/۵ سم. ۴۸ گ. ۱۶ س.\n(ثبت ۴۹۲۸۸)", $found['copy_text']);
        $this->assertSame([119, null, 119], [$found['page_start'], $found['page_end'], $found['shelfmark_page']]);
        // the page it does not stand on
        $this->assertNull($this->majlis('6547', 121));
    }

    public function test_a_majlis_treatise_is_read_with_its_volume_and_the_volume_s_description()
    {
        $found = $this->majlis('6548/2', 120);
        $this->assertSame("۶۵۴۸\nمجموعه\n۲. صغری (منطق ـ فارسی)\n(۱۴ پ ـ ۱۸ پ)\nخط خوش در این رساله.", $found['entry_text']);
        $this->assertStringStartsWith('خط: رسالۀ اول نسخ', $found['copy_text']);
        $this->assertStringEndsWith('(ثبت ۵۰۱۲۳)', $found['copy_text']);
        $this->assertSame([120, 121, 120], [$found['page_start'], $found['page_end'], $found['shelfmark_page']]);
        // the last treatise ends where the volume's description begins; Fankha cites it on the next page too
        $last = $this->majlis('6548/3', 121);
        $this->assertStringEndsWith("از: اثیرالدین ابهری (۶۶۳ه).", $last['entry_text']);
        // a stray number line is no heading; the next copy is headed in brackets
        $this->assertStringStartsWith("(۶۵۴۹)\nمجموعه:\n۱. [ادعیه]", $this->majlis('6549/1', 121)['entry_text']);
        // a corrected shelfmark names a treatise the volume does not list: nothing is taken for it
        $this->assertNull($this->majlis('6548/9', 120, ['strict_part' => true]));
        $this->assertStringStartsWith("۶۵۴۸\nمجموعه\n۱. القصیدة", $this->majlis('6548/9', 120)['entry_text']);
    }

    public function test_a_marvi_copy_is_its_work_and_its_own_line()
    {
        $found = $this->marvi('394', 262);
        $this->assertSame("( ش ۳۹۱ و۳۹۴ )\nنهج البلاغة ( حدیث ، عربی )\nگردآوری سیدرضی متوفای ۴۰۶ .", $found['entry_text']);
        $this->assertSame('* ش ۳۹۴ ، وزیری ، تحریر سده ۱۱ و۱۲ .', $found['copy_text']);
        // a copy under a heading the print left unreadable, with no comma after its number
        $this->assertSame('* ش ۱۲۴ رحلی ، جلد یازدهم .', $this->marvi('124', 263)['copy_text']);
        // a Quran, described in its entry alone
        $quran = $this->marvi('963', 263);
        $this->assertSame(['( ش ۹۶۳ )', 'به خط نسخ ، تاریخ ۱۲۳۱ .'], [$quran['entry_text'], $quran['copy_text']]);
        // a footnote is no copy line, and a number on another page is not found
        $this->assertNull($this->marvi('394', 290));
    }

    public function test_a_marvi_treatise_is_read_with_its_volume_s_heading()
    {
        $found = $this->marvi('651/2', 290);
        $this->assertSame('۲ - رسالة فی خلق الاعمال از ملا صدرا ، تحریر ۱۲۷۹ .', $found['entry_text']);
        $this->assertSame("( ش ۶۵۱ - خشتی )\n۲ - رسالة فی خلق الاعمال از ملا صدرا ، تحریر ۱۲۷۹ .", $found['copy_text']);
        $this->assertSame('۱ - خرائج راوندی ، تحریر ۱۲۷۱ .', $this->marvi('657/1', 290)['entry_text']);
    }

    public function test_the_command_reads_a_one_volume_catalogue_s_citation_of_a_page_alone()
    {
        $library = Library::create(['name' => 'مروی', 'city' => 'تهران', 'slug' => 'marvi-' . uniqid()]);
        $source = Source::create(['code' => 'catalogue-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10, 'title' => 'فهرست مروی', 'library_id' => $library->id]);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1, 'facsimile_code' => 'marvi-test', 'page_first' => 4, 'page_last' => 334]);
        foreach (self::MARVI as $page => $text) {
            DB::table('source_pages')->insert(['source_volume_id' => $volume->id, 'page' => $page, 'text' => $text]);
        }
        $cite = function (string $shelfmark, ?int $page, string $citation) use ($library, $source, $volume) {
            $work = Work::create(['primary_title' => 'اثر ' . $shelfmark, 'clean_title' => 'اثر ' . $shelfmark, 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
            $copy = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => $shelfmark, 'volume_number' => 1, 'page_start' => 1]);

            return SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $copy->id, 'source_id' => $source->id, 'source_volume_id' => $volume->id,
                'page_start' => $page, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_FANKHA_CITATION,
                'status' => SourceEntry::STATUS_UNVERIFIED, 'citation_text' => $citation]);
        };
        $paged = $cite('211', 262, '[ف: 1-262]');
        $alone = $cite('651/2', null, '[ف: 290]');
        $nothing = $cite('657/1', null, '[ف]');

        $this->artisan('sources:take-texts', ['source' => $source->code, '--extractor' => 'listed_shelfmarks'])
            ->expectsTable(['', 'count'], [['without text', '3'], ['no page or pages', '1'], ['not found', '0'], ['taken', '2'], ['page from the citation', '1'], ['by a misprint', '0'], ['address changed', '0']])
            ->assertSuccessful();
        $this->assertSame([SourceEntry::TEXT_AUTO, 262], [$paged->fresh()->text_status, $paged->fresh()->page_start]);
        $alone->refresh();
        $this->assertSame([SourceEntry::TEXT_AUTO, 290, 290], [$alone->text_status, $alone->page_start, $alone->shelfmark_page]);
        $this->assertSame(SourceEntry::TEXT_NONE, $nothing->fresh()->text_status);
        // the rollback gives the page back as it was: none
        $log = AuditLog::where('auditable_type', SourceEntry::class)->where('auditable_id', $alone->id)->latest('id')->firstOrFail();
        $this->assertNull($log->old_values['page_start']);
        // the page view marks the copy's line, though Fankha's citation names no volume
        Extractors::forget();
        $this->get("/sources/{$source->code}/1/page/290")->assertOk()
            ->assertSee('<a href="' . route('manuscripts.show', $alone->citable) . '" class="book-sheet__mark book-sheet__mark--manuscript"', false);
    }
}
