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
        $this->assertSame([['residence', 'دمشق', [714]]], $this->read('و انتقل إلى دمشق سنة 714 ه.'));
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

    public function test_the_errors_a_sample_of_proposals_showed_are_left_out()
    {
        // someone else's life: a father, a son, a forebear (step 14.227)
        $this->assertSame([], $this->read('هاجر منها أبوه إلى دمشق'));
        $this->assertSame([], $this->read('یکی از اجدادش از بخارا بدهلی مهاجرت کرده و در آنجا ساکن شده'));
        $this->assertSame([], $this->read('حاج سید محمد مجتهد مقیم کرمانشاهان فرزند این عالم بزرگوار است'));
        // «نزيل» after a name describes that name; at the head of a clause it describes the person
        $this->assertSame([], $this->read('و في تكملة الرجال للشيخ عبد النبي الكاظمي نزيل بغداد'));
        $this->assertSame([['residence', 'تهران', []]], $this->read('نزيل طهران'));
        // «حضر» alone is no study; «مدينة قم» is no Medina; «طرابلس الشام» is no Damascus; «كربلاء» is Karbala
        $this->assertSame([], $this->read('و حضر دفنه الملك الناصر سلطان الشام'));
        $this->assertSame([['study', 'نجف', []]], $this->read('حضر في النجف الأشرف على حبيب اللّه الرشتي'));
        $this->assertSame([['study', 'قم', []]], $this->read('فقرأ بمدينة قم على المحقّق القمي'));
        $this->assertSame([['residence', 'مدینه', []]], $this->read('نزيل المدينة المنورة'));
        $this->assertSame([], $this->read('فولي قضاء طرابلس الشام'));
        $this->assertSame([], $this->read('و انتقل الى الشام'));
        $this->assertSame([['residence', 'دمشق', []]], $this->read('و انتقل الى دمشق الشام'));
        // of two names side by side before a Persian verb the first is the place; a forebear's move is not his
        $this->assertSame([['residence', 'جهرم', []]], $this->read('عالم فاضل بجهرم شیراز مهاجرت کرد'));
        $this->assertSame([], $this->read('هذا اول من نزل من آبائه من تبريز إلى قزوين'));
        $this->assertSame([['travel', 'کربلا', []]], $this->read('و ورد إلى كربلاء لبعض الفتن التي وقعت بالنجف'));
        // «درس في» is a study; with its mark, «درّس», a teaching
        $this->assertSame([['study', 'نجف', []]], $this->read('و قد درس في النجف'));
        // a Persian verb takes the place just before it, not one of an earlier phrase
        $this->assertSame([], $this->read('مقدماترا در قزوین بپایان رسانیده و بعد بعتبات مهاجرت نموده'));
        // a year far from the place is of something else
        $this->assertSame([['residence', 'بروجرد', [1255]]], $this->read('بسال 1255 به بروجرد رفته و فقه و اصول را نزد علمای آنجا تکمیل و از آنجا به طهران مسافرت‌هائی نموده'));
        $this->assertSame([['study', 'نجف', []]], $this->read('بعدا بنجف شتافته و بادامه تحصیل پرداخته و بعد از سه سال توقف بموطن خود بازگشتم و در سال 1331 ق در گلپایگان متأهل شدم'));
        $this->assertSame([['residence', 'قاهره', [736]]], $this->read('در سال 736 بمصر رفته و با فضلای نامی آنجا ملاقات کرده'));
        // the cue far into a long clause tells of something else
        $this->assertSame([], $this->read('و كان كريما سخيا ذا همة عالية و عطاياه تغني الفقراء أقل عطاياه ألف شامي أو ألفا شامي حتى انه لما سافر إلى ايران و وصل إلى طهران'));
    }
}
