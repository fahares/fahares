<?php

namespace Tests\Unit;

use App\Support\CopyDateRange;
use PHPUnit\Framework\TestCase;

/**
 * A copy's date as a range of Hijri years, with the catalogue's hedge word (backlog item 96)
 */
class CopyDateRangeTest extends TestCase
{
    private function range(string $raw): array
    {
        $r = CopyDateRange::parse($raw);

        return [$r['from'], $r['to']];
    }

    public function test_centuries_and_their_parts()
    {
        $this->assertSame([1201, 1300], $this->range('قرن 13'));
        $this->assertSame([1101, 1300], $this->range('قرن 12 و 13'));
        $this->assertSame([1101, 1300], $this->range('قرن 12-13'));
        $this->assertSame([1201, 1233], $this->range('اوایل قرن 13'));
        $this->assertSame([1234, 1266], $this->range('اواسط قرن 13'));
        $this->assertSame([1167, 1200], $this->range('اواخر قرن 12'));
        $this->assertSame([1051, 1100], $this->range('نیمه دوم قرن 11'));
        $this->assertSame([1201, 1300], $this->range('قرن ۱۳'));
    }

    public function test_years_spans_and_open_ends()
    {
        $this->assertSame([1246, 1246], $this->range('3 رمضان 1246ق'));
        $this->assertSame([1082, 1083], $this->range('1082 و 1083ق'));
        $this->assertSame([1034, 1052], $this->range('1034 تا 1052ق'));
        $this->assertSame([1081, 1091], $this->range('حدود 1086ق'));
        $this->assertSame([null, 1100], $this->range('پیش از 1100'));
        $this->assertSame([1100, null], $this->range('پس از 1100'));
    }

    public function test_the_date_is_the_first_clause_with_a_year_and_other_calendars_are_left_out()
    {
        // The year after a «،» inside the date, and not the year of the copy it was made from
        $this->assertSame([1268, 1268], $this->range('18 ربیع الثانی، 1268'));
        $this->assertSame([1122, 1122], $this->range('1122، از روی نسخه‌ای در 616'));
        $this->assertSame([647, 648], $this->range('1250م'));
        $this->assertSame([null, null], $this->range('نزدیک عصر مؤلف'));
    }

    public function test_hedge_words_and_their_certainty()
    {
        $this->assertSame(['probable', 'محتملاً'], array_values(array_intersect_key(CopyDateRange::parse('محتملاً قرن 11'), ['hedge' => 0, 'certainty' => 0])));
        $this->assertSame(['doubtful', '؟'], array_values(array_intersect_key(CopyDateRange::parse('1000 (؟)'), ['hedge' => 0, 'certainty' => 0])));
        $this->assertSame([null, null], array_values(array_intersect_key(CopyDateRange::parse('قرن 13'), ['hedge' => 0, 'certainty' => 0])));
    }

    public function test_a_date_that_names_the_authors_time_is_ranged_from_the_authors_death()
    {
        $r = fn (string $raw) => array_values(CopyDateRange::relative($raw, 1050) ?? []);
        $this->assertSame([1000, 1050, 'probable', 'در حیات مؤلف'], $r('در حیات مؤلف'));
        $this->assertSame([1000, 1050, 'probable', 'در زمان حیات مؤلف'], $r('در زمان حیات مؤلف'));
        $this->assertSame([1000, 1050, 'probable', 'عصر تألیف'], $r('در عصر تألیف'));
        $this->assertSame([1000, 1075, 'probable', 'در عصر مؤلف'], $r('در عصر مؤلف'));
        $this->assertSame([1000, 1075, 'probable', 'از عصر مؤلف'], $r('از عصر مؤلف و به خط وی تصحیح شده'));
        $this->assertSame([1000, 1075, 'probable', 'عصر مولف'], $r('عصر مولف'));
        $this->assertSame([1050, 1125, 'probable', 'نزدیک عصر مؤلف'], $r('نزدیک عصر مؤلف'));
        $this->assertSame([1050, 1125, 'probable', 'نزدیک به زمان ناظم'], $r('نزدیک به زمان ناظم'));
        $this->assertSame([1000, 1075, 'probable', 'ظاهراً در عصر مؤلف'], $r('ظاهراً در عصر مؤلف'));
        $this->assertSame([1000, 1075, 'doubtful', 'شاید از عصر مؤلف'], $r('شاید از عصر مؤلف'));
        // A year of its own, another person's time, or no death year: not relative
        $this->assertNull(CopyDateRange::relative('1080، در عصر مؤلف', 1050));
        $this->assertNull(CopyDateRange::relative('قرن 11، عصر مؤلف', 1050));
        $this->assertNull(CopyDateRange::relative('عصر مترجم', 1050));
        $this->assertNull(CopyDateRange::relative('دوره صفوی', 1050));
        $this->assertNull(CopyDateRange::relative('نزدیک عصر مؤلف', null));
    }

    /** Step 14.73: «ق 10», two bare centuries, and a later century first. */
    public function test_a_century_written_short_bare_or_backwards()
    {
        $this->assertSame([901, 1100], $this->range('احتمالا ق 10 و 11'));
        $this->assertSame([1201, 1300], $this->range('ق. 13'));
        $this->assertSame([901, 1100], $this->range('10 و 11'));
        $this->assertSame([1201, 1400], $this->range('احتمالاً 13-14'));
        $this->assertSame([1267, 1333], $this->range('ظاهراً در اواخر 13 یا اوایل 14'));
        $this->assertSame([1001, 1200], $this->range('قرن 12-11'));
        $this->assertSame([601, 800], $this->range('ق 7 یا قرن 8'));
        $this->assertSame([901, 1100], $this->range('ق 10 یا11'));
        $this->assertSame([901, 1100], $this->range('احتمالاً ق 10 یا قرن 11'));
        $this->assertSame([1101, 1233], $this->range('ق 12 یا اوائل قرن 13'));
        $this->assertSame([1167, 1233], $this->range('اواخر قرن 12 تا اوایل قرن 13'));
        $this->assertSame([1251, 1333], $this->range('نیمه دوم قرن 13 یا اوایل قرن 14 ق'));
        $this->assertSame([901, 1100], $this->range('قرن 10 یا 11'));
        $this->assertSame('probable', CopyDateRange::parse('احتمالاً 13-14')['certainty']);
        // not centuries: a year, two days of a month, one bare number, numbers too far apart or too large
        $this->assertSame([1268, 1268], $this->range('1268ق'));
        $this->assertSame([null, null], $this->range('رمضان 10 و 11'));
        $this->assertSame([null, null], $this->range('13'));
        $this->assertSame([null, null], $this->range('10 و 14'));
        $this->assertSame([null, null], $this->range('15 و 16'));
    }

    public function test_a_short_year_a_day_a_stray_number_or_a_reference_is_no_year()
    {
        // a year written short beside its full form (step 14.175)
        $this->assertSame([1012, 1012], $this->range('یک‌شنبه 12 صفر 1012=112ق'));
        $this->assertSame([1126, 1126], $this->range('7 ربیع الاول 126 (گویا 1126)'));
        $this->assertSame([1045, 1045], $this->range('4 رمضان 1045ق (145)'));
        $this->assertSame([1280, 1280], $this->range('ذیقعده 1280=128ق'));
        // a three-digit day before a month, and a number far before the clause's year
        $this->assertSame([839, 839], $this->range('232 صفر 839ق'));
        $this->assertSame([1294, 1294], $this->range('104 (کذ) شوال 1294ق'));
        $this->assertSame([1309, 1309], $this->range('138-1309ق'));
        $this->assertSame([1264, 1264], $this->range('یک‌شنبه 17 شعبان ... 126 و پنج‌شنبه ... ذیقعده 1264ق'));
        // a page or a volume reference, and a solar date with slashes
        $this->assertSame([1058, 1066], $this->range('4 ربیع الاول 1066 (ص 103) و ربیع الثانی 1058 (ص 115)'));
        $this->assertSame([1157, 1157], $this->range('تاریخ تألیف آن در الذریعة (186/25) شعبان 1157ق آمده'));
        $this->assertSame([null, null], $this->range('317/11/7ش'));
        // what stays: two years of one date, a span of a century, a three-digit year alone
        $this->assertSame([990, 1001], $this->range('990 و 1001ق'));
        $this->assertSame([850, 920], $this->range('850 و 920ق'));
        $this->assertSame([990, 990], $this->range('رجب 990'));
        $this->assertSame([1256, 1256], $this->range('10 ج1256ق'));
        $this->assertSame([967, 1207], $this->range('1116 و 1019 و 1181 و 1124 و 1207 و 967ق'));
        $this->assertSame([859, 1236], $this->range('859ق و 1236ق'));
        $this->assertSame([845, 1098], $this->range('845-1098ق'));
        $this->assertSame(1207, \App\Support\HijriYear::parse('127 (=1207ق)'));
        $this->assertSame(1126, \App\Support\HijriYear::parse('7 ربیع الاول 126 (گویا 1126)'));
    }

    public function test_a_solar_or_gregorian_date_spans_the_hijri_years_it_falls_in()
    {
        // step 14.181: the catalogue's own pairs («1383ش = 1425ق», «1359ش = 1400ق», «1254ش = 1292ق») agree
        $this->assertSame([1425, 1426], $this->range('1383ش'));
        $this->assertSame([1400, 1401], $this->range('1359ش'));
        $this->assertSame([1292, 1293], $this->range('1254ش'));
        $this->assertSame([1303, 1304], $this->range('1886م'));
        $this->assertSame([1353, 1354], $this->range('1314/8/12ش'));
        $this->assertSame(1411, \App\Support\HijriYear::parse('1370ش'));
        // a Hijri year in the text wins
        $this->assertSame([1400, 1400], $this->range('1359ش = 1400ق'));
        $this->assertSame([1304, 1304], $this->range('1886م؛ تاریخ ترجمه: 1304ق'));
        $this->assertSame(1304, \App\Support\HijriYear::parse('1886م؛ تاریخ ترجمه: 1304ق'));
    }
}

