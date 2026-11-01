<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Source;
use App\Models\SourceVolume;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sources:link-dharia-people (step 13.133): the persons Noor tags in al-Dhari'a's text linked to the site's people, and
 * the page showing them.
 */
class LinkDhariaPeopleTest extends TestCase
{
    public function test_a_tagged_name_links_the_one_person_whose_whole_name_it_holds()
    {
        $source = Source::create(['code' => 'dharia', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'الذریعة']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 4, 'facsimile_code' => 'dharia-4']);
        $person = fn (string $name, ?int $death = null, ?array $aliases = null) => Person::create(['name' => $name, 'normalized_name' => $name,
            'slug' => 'p-' . uniqid(), 'is_author' => true, 'death_year_hijri' => $death, 'aliases' => $aliases]);
        $golpayegani = $person('فخرالدین بن ابی القاسم گلپایگانی');
        $tunekabuni = $person('تنکابنی، محمد بن عبدالفتاح', 1124);
        $hamza = $person('حسینی، حمزه بن محمد');
        $common = $person('استرآبادی، محمد بن حسن');
        $majlisi = $person('مجلسی، محمد باقر بن محمد تقی', 1110, ['علامه مجلسی', 'محمدباقر مجلسی']);
        $yazdi = $person('یزدی، عبدالله', 1150, ['عبدالله یزدی']);
        $sadr = $person('موسوی، صدرالدین', 1250, ['صدرالدین موسوی']);
        $person('اصفهانی، محمد بن جعفر');
        $person('اصفهانی، محمد بن جعفر');

        $m = fn (string $text) => "\u{E020}FB\u{E021}{$text}\u{E022}";
        $text = implode(' و ', [
            $m('الشيخ فخر الدين بن أبي القاسم الگلپايگاني'),
            $m('المولى محمد بن عبد الفتاح التنكابني') . ' المتوفى 1124',
            // the father of the one named, not he
            $m('السيد حمزة بن حمزة بن محمد الحسيني'),
            // a name too common to say whom
            $m('محمد بن الحسن الأسترآبادي'),
            $m('العلامة المجلسي'),
            // an alias that is the given name and nisba only
            $m('المولى عبد الله اليزدي'),
            // living past the death of the one of that name
            $m('السيد صدر الدين الموسوي') . ' ( 1272 - 1354 )',
            // two persons of that name
            $m('محمد بن جعفر الأصفهاني'),
        ]);
        $page = DB::table('source_pages')->insertGetId(['source_volume_id' => $volume->id, 'page' => 10, 'text' => $text]);

        $this->artisan('sources:link-dharia-people --all')->assertSuccessful();
        $this->assertSame([
            [0, $golpayegani->id, 'name'],
            [1, $tunekabuni->id, 'name+year'],
            [4, $majlisi->id, 'alias'],
        ], DB::table('source_page_links')->orderBy('ordinal')->get()->map(fn ($l) => [$l->ordinal, $l->target_id, $l->method])->all());
        $this->assertSame([], array_intersect([$hamza->id, $common->id, $yazdi->id, $sadr->id],
            DB::table('source_page_links')->pluck('target_id')->all()));

        $html = $this->get('/sources/dharia/4/page/10')->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . route('people.show', $majlisi) . '" class="book-sheet__mark book-sheet__mark--person-guess" title="مجلسی، محمد باقر بن محمد تقی (پیوند ماشینی)" data-noor="FB" data-record-link="person-guess">العلامة المجلسي</a>', $html);
        $this->assertStringContainsString('<span data-noor="FB">المولى عبد الله اليزدي</span>', $html);
        $this->assertSame($page, DB::table('source_page_links')->value('source_page_id'));

        // without --all only a name his death year after it confirms is saved
        $this->artisan('sources:link-dharia-people')->assertSuccessful();
        $this->assertSame([[1, $tunekabuni->id]], DB::table('source_page_links')->get()->map(fn ($l) => [$l->ordinal, $l->target_id])->all());
    }
}
