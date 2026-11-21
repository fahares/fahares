<?php

namespace Tests\Unit;

use App\Support\PersonOrigins;
use Tests\TestCase;

/** Where a person was from, was born and died, from the name and the lives (backlog item 137, step 14.276). */
class PersonOriginsTest extends TestCase
{
    public function test_a_nisba_of_the_name_points_to_its_city()
    {
        $this->assertSame(['اصفهان'], PersonOrigins::fromName('محمد قاسم بن محمد صالح اصفهانی'));
        $this->assertSame(['اصفهان'], PersonOrigins::fromName('اصفهانی، محمد مقیم بن محمد باقر'));
        // The Arabic article, another name of the city, and a nisba made in a way of its own
        $this->assertSame(['حله'], PersonOrigins::fromName('العلامة الحلي، الحسن بن يوسف'));
        $this->assertSame(['تهران'], PersonOrigins::fromName('آقا بزرگ طهرانی'));
        $this->assertSame(['هرات'], PersonOrigins::fromName('عبدالله انصاری هروی'));
        $this->assertSame(['کربلا'], PersonOrigins::fromName('احمد بن ابراهیم حائری'));
        $this->assertSame(['تفرش', 'نجف'], PersonOrigins::fromName('فخرالدین بن علی حسینی تفرشی نجفی'));
    }

    public function test_a_word_that_is_no_nisba_names_no_city()
    {
        // The city itself, a pilgrim's title, a note in brackets, and a longer word that holds a nisba
        $this->assertSame([], PersonOrigins::fromName('محمد کاتب اصفهان'));
        $this->assertSame([], PersonOrigins::fromName('مشهدی حسن بن کربلایی علی'));
        $this->assertSame([], PersonOrigins::fromName('محمد حسین (شاگرد میرزا شیرازی)'));
        $this->assertSame([], PersonOrigins::fromName('علی قمیان'));
        $this->assertSame([], PersonOrigins::fromName(null));
    }

    public function test_a_life_names_the_cities_of_birth_death_and_origin()
    {
        $hits = fn (string $text) => array_map(fn ($hit) => $hit['kind'] . ' ' . $hit['city'], PersonOrigins::fromBiography($text));

        $this->assertSame(['origin استرآباد'], $hits('مجتهد إمامي، من أهل أستراباد. نشأ في كربلاء و نزل بطهران و توفي بها .'));
        $this->assertSame(['birth تهران'], $hits('أصله من بلدة نور بمازندران و مولده بطهران.'));
        $this->assertSame(['birth اصفهان', 'death نجف'], $hits('ولد سنة 1200 في أصفهان و قرأ على علمائها ثم توفّي في النجف الأشرف سنة 1270.'));
        $this->assertSame(['birth شیراز', 'death تهران'], $hits('در شیراز متولد شده. نزد فاضل اردکانی درس خوانده و در طهران درگذشت.'));
        $this->assertSame(['death ری'], $hits('مات سنة 395 بالري'));
    }

    public function test_a_life_is_not_read_beyond_what_it_says()
    {
        $hits = fn (string $text) => array_map(fn ($hit) => $hit['kind'] . ' ' . $hit['city'], PersonOrigins::fromBiography($text));

        // A kinsman's birth, a body carried elsewhere, a country, a city a sentence away, and no place at all
        $this->assertSame([], $hits('ولد ابوه في القطيف و هاجر'));
        $this->assertSame([], $hits('توفي سنة 1151 و نقل إلى المشهد الرضوي'));
        $this->assertSame([], $hits('توفي بمصر و كان من أهل الشام'));
        $this->assertSame([], $hits('توفي سنة 948. من أجداد السيد علي خان و صاحب المدرسة المنصورية بشيراز'));
        $this->assertSame([], $hits('توفي بها'));
        // «some of the people of Isfahan» are others, not the person
        $this->assertSame([], $hits('در شرح بعضی احادیث مشکله که بدرخواست بعضی از اهالی اصفهان تألیف شده'));
        $this->assertSame([], $hits('روى عنه جمع من أهل بغداد'));
        $this->assertSame([], PersonOrigins::fromBiography(null));
    }

    public function test_mashhad_in_an_arabic_life_is_the_shrine_it_names()
    {
        $city = fn (string $text) => PersonOrigins::fromBiography($text)[0]['city'] ?? null;

        $this->assertSame('مشهد', $city('توفي في المشهد الرضوي'));
        $this->assertSame('مشهد', $city('ولد في مشهد و نشأ بها'));
        $this->assertSame('کربلا', $city('توفي في مشهد الحسين'));
        $this->assertSame('نجف', $city('توفي بالمشهد الغروي'));
        $this->assertNull($city('توفي في مشهد عبد العظيم'));
    }

    public function test_every_nisba_of_the_config_names_a_city_of_the_map()
    {
        $cities = array_keys(config('insights.copy_places'));
        foreach (config('insights.nisbas') as $nisba => $city) {
            $this->assertContains($city, $cities, $nisba);
            $this->assertStringNotContainsString("\u{200C}", $nisba, 'a nisba of two words is never one word of a name');
        }
        $this->assertSame('هرات', PersonOrigins::nisbas()['هروی']);
        $this->assertArrayNotHasKey('مشهدی', PersonOrigins::nisbas());
    }
}
