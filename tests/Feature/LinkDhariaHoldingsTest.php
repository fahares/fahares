<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sources:link-dharia-holdings and sources:link-dharia-subjects (step 13.145): the libraries Noor tags in al-Dhari'a's
 * text as holding a copy, and the subjects, linked to the site's libraries and subjects, and the page showing them.
 */
class LinkDhariaHoldingsTest extends TestCase
{
    public function test_a_tagged_library_of_the_list_links_the_sites_library()
    {
        $source = Source::create(['code' => 'dharia', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'الذریعة']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 19, 'facsimile_code' => 'dharia-19']);
        $library = fn (string $city, string $name) => Library::create(['city' => $city, 'name' => $name, 'slug' => 'l-' . uniqid()]);
        $razavi = $library('مشهد', 'رضوی');
        $majlis = $library('تهران', 'مجلس');
        $ilahiyat = $library('تهران', 'الهیات');
        $library('مشهد', 'الهیات');
        $malek = $library('تهران', 'ملک');

        $m = fn (string $text) => "\u{E020}OB\u{E021}{$text}\u{E022}";
        $text = 'في ( ' . $m('الرضوية)') . ' و ' . $m('مكتبة (المجلس)') . ' و ( ' . $m('بطهران (إلهيات:') . ' 806/1 د) و '
            // a private holding, a library the site does not have, a place alone
            . $m('عند (الملك)') . ' و ' . $m('الآصفية') . ' و ' . $m('النجف') . ' و ' . $m('المكتبة (الرضوية) بخراسان') . '.';
        $page = DB::table('source_pages')->insertGetId(['source_volume_id' => $volume->id, 'page' => 170, 'text' => $text]);

        $this->artisan('sources:link-dharia-holdings')->assertSuccessful();
        $this->assertSame([
            [0, $razavi->id], [1, $majlis->id], [2, $ilahiyat->id], [6, $razavi->id],
        ], DB::table('source_page_links')->orderBy('ordinal')->get()->map(fn ($l) => [$l->ordinal, $l->target_id])->all());
        $this->assertSame(0, DB::table('source_page_links')->where('target_id', $malek->id)->count());

        $html = $this->get('/sources/dharia/19/page/170')->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . route('libraries.show', $majlis) . '" id="m1" class="book-sheet__mark book-sheet__mark--library" title="کتابخانه‌ی مجلس، تهران (پیوند ماشینی)" data-noor="OB" data-record-link="library">مكتبة (المجلس)</a>', $html);
        $this->assertStringContainsString('<span data-noor="OB">عند (الملك)</span>', $html);
        $this->assertSame($page, DB::table('source_page_links')->value('source_page_id'));
    }

    public function test_a_tagged_subject_of_the_list_links_the_sites_subject()
    {
        $source = Source::create(['code' => 'dharia', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'الذریعة']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 2, 'facsimile_code' => 'dharia-2']);
        $fiqh = Subject::create(['name' => 'فقه', 'slug' => 's-' . uniqid()]);
        $usul = Subject::create(['name' => 'اصول فقه', 'slug' => 's-' . uniqid()]);
        $kalam = Subject::create(['name' => 'کلام و اعتقادات', 'slug' => 's-' . uniqid()]);

        $m = fn (string $text) => "\u{E020}UB\u{E021}{$text}\u{E022}";
        // a form of no subject of the list, and one that may be of two
        $text = 'في ' . $m('الفقه') . ' و ' . $m('أصول الفقه') . ' و ' . $m('رسالة عملية') . ' و ' . $m('في أصول الدين') . ' و '
            . $m('شرح أحواله') . ' و ' . $m('الأصول') . '.';
        DB::table('source_pages')->insertGetId(['source_volume_id' => $volume->id, 'page' => 5, 'text' => $text]);

        $this->artisan('sources:link-dharia-subjects')->assertSuccessful();
        $this->assertSame([[0, $fiqh->id], [1, $usul->id], [2, $fiqh->id], [3, $kalam->id]],
            DB::table('source_page_links')->orderBy('ordinal')->get()->map(fn ($l) => [$l->ordinal, $l->target_id])->all());

        $html = $this->get('/sources/dharia/2/page/5')->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . route('subjects.show', $usul) . '" id="m1" class="book-sheet__mark book-sheet__mark--subject" title="موضوع: اصول فقه (پیوند ماشینی)" data-noor="UB" data-record-link="subject">أصول الفقه</a>', $html);
        $this->assertStringContainsString('<span data-noor="UB">الأصول</span>', $html);
    }
}
