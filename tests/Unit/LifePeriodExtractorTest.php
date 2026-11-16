<?php

namespace Tests\Unit;

use App\Support\LifePeriodExtractor;
use Tests\TestCase;

/**
 * The periods of a life a biography tells of, as proposals for a reviewer (item 139, step 14.220)
 */
class LifePeriodExtractorTest extends TestCase
{
    private function read(string $text): array
    {
        return array_map(fn ($p) => [$p['kind'], $p['city'], $p['years']], LifePeriodExtractor::extract($text));
    }

    public function test_a_clause_with_a_cue_and_a_city_after_it_is_a_proposal()
    {
        $this->assertSame([['residence', 'تهران', []]], $this->read('انتقل المترجم الى طهران، و بقى فيها نحوا من عشر سنين.'));
        $this->assertSame([['residence', 'دمشق', [714]]], $this->read('و انتقل به أبوه إلى دمشق سنة 714 ه.'));
        $this->assertSame([['residence', 'بغداد', []]], $this->read('سَكَنَ ببغداد مدة.'));
        $this->assertSame([['office', 'دمشق', []]], $this->read('و ناب في القضاء بدمشق'));
        $this->assertSame([['travel', 'بغداد', [355]]], $this->read('و كان ورد بغداد سنة 355 و سمع منه شيوخ الطائفة'));
        $this->assertSame([['study', 'نجف', []]], $this->read('پس از تحصیلات مقدماتی به نجف اشرف مهاجرت نموده.'));
        $this->assertSame([['residence', 'قم', [1298]]], $this->read('در سنۀ 1298 ق بقم مراجعت کرده و مشغول شد'));
        // «درس على» is a study, «درس في» a teaching
        $this->assertSame([['study', 'نجف', []]], $this->read('درس على الشيخ الانصاري في النجف'));
        $this->assertSame([['teaching', 'اصفهان', []]], $this->read('درّس في أصفهان'));
        // several clauses, one proposal per kind and city; a later clause that brings years replaces an earlier one
        $this->assertSame([['residence', 'حلب', [730]], ['travel', 'قاهره', []]], $this->read('ثم انتقل إلى حلب. و رحل إلى مصر. و سكن حلب سنة 730.'));
    }

    public function test_what_is_no_period_is_left_out()
    {
        // a birth, a death, a burial; a cue with no city of the list; a city before the cue or far after it
        $this->assertSame([], $this->read('ولد ببغداد، و توفي بدمشق، و دفن في النجف.'));
        $this->assertSame([], $this->read('سكن قرية من قرى الجبل'));
        $this->assertSame([], $this->read('كان من أهل بغداد ثم سكن'));
        $this->assertSame([], $this->read('انتقل مع والده و اخوته و جميع من كان معه من اهله و عياله الى بغداد'));
        // the place he left, a shrine that is not Mashhad, a Gregorian year
        $this->assertSame([['residence', 'اصفهان', []]], $this->read('هاجر من يزد إلى أصفهان للتحصيل'));
        $this->assertSame([], $this->read('و وصلنا إلى المشهد المقدس الكاظمي فأقمنا به'));
        $this->assertSame([['residence', 'مشهد', []]], $this->read('نزيل المشهد الرضوي'));
        $this->assertSame([['residence', 'قاهره', []]], $this->read('و استقر في القاهرة سنة 1910 م'));
        $this->assertSame([], $this->read(''));
    }
}
