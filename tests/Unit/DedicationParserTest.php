<?php

namespace Tests\Unit;

use App\Support\DedicationParser;
use App\Support\KnownRulers;
use PHPUnit\Framework\TestCase;

/**
 * The people of the Fankha «اهدا / به درخواست» field, with their role, note and hedge (backlog item 35, step 2)
 */
class DedicationParserTest extends TestCase
{
    private function names(string $text): array
    {
        return array_map(fn ($p) => [$p['role'], $p['name']], DedicationParser::parse($text));
    }

    public function test_people_are_split_on_va_and_semicolon_with_dates_in_the_note()
    {
        $people = DedicationParser::parse('ناصرالدین شاه قاجار (1264-1313ق) و وزیرش آقاخان نوری');
        $this->assertSame([['dedicatee', 'ناصرالدین شاه قاجار'], ['dedicatee', 'وزیرش آقاخان نوری']], array_map(fn ($p) => [$p['role'], $p['name']], $people));
        $this->assertSame(['1264-1313ق', 1313], [$people[0]['note'], $people[0]['death']]);
        $this->assertSame([['dedicatee', 'شیخ بهائی'], ['dedicatee', 'شاه عباس']], $this->names('شیخ بهائی؛ به نام: شاه عباس (995-1038ق)'));
        // « و » inside parentheses does not split
        $this->assertSame([['dedicatee', 'رستم پاشا']], $this->names('رستم پاشا (وزیر سلطان سلیمان و داماد او)'));
    }

    public function test_a_request_marker_makes_a_commissioner_and_carries_over()
    {
        $this->assertSame([['commissioner', 'عماد الملک طبسی'], ['dedicatee', 'ناصرالدین شاه']], $this->names('به دستور عماد الملک طبسی و به نام ناصرالدین شاه'));
        $this->assertSame([['commissioner', 'حاج ملا محمد صالح برغانی'], ['commissioner', 'آقا صالح']], $this->names('به درخواست حاج ملا محمد صالح برغانی و آقا صالح'));
        $this->assertSame([['commissioner', 'میرزا ابوالقاسم']], $this->names("حسب\u{200c}الامر میرزا ابوالقاسم"));
        $this->assertSame([['dedicatee', 'امین الملک']], $this->names('برای امین الملک'));
        // «بهنام» is a name, not «به نام»
        $this->assertSame([['dedicatee', 'بهنام میرزا']], $this->names('بهنام میرزا'));
    }

    public function test_hedges_are_kept_apart_from_the_name()
    {
        $this->assertSame(['ناصر الدین شاه', 'شاید'], array_values(array_intersect_key(DedicationParser::parse('ناصر الدین شاه (شاید)')[0], ['name' => 0, 'hedge' => 0])));
        $this->assertSame(['ناصرالدین شاه', 'محتملاً'], array_values(array_intersect_key(DedicationParser::parse('محتملاً ناصرالدین شاه')[0], ['name' => 0, 'hedge' => 0])));
        $this->assertSame(['شاه سلیمان', '؟'], array_values(array_intersect_key(DedicationParser::parse('شاه سلیمان ؟')[0], ['name' => 0, 'hedge' => 0])));
        // A parenthesis the catalogue left open goes to the note
        $this->assertSame(['ناصرالدین شاه قاجار', '1264-1313ق'], array_values(array_intersect_key(DedicationParser::parse('ناصرالدین شاه قاجار (1264-1313ق')[0], ['name' => 0, 'note' => 0])));
    }

    public function test_known_rulers_are_found_by_any_spelling_but_not_when_ambiguous()
    {
        $this->assertSame('ناصرالدین قاجار، شاه ایران', KnownRulers::find('ناصر الدین شاه')['ruler']['name']);
        $this->assertSame('فتحعلی شاه قاجار، شاه ایران', KnownRulers::find('فتح علی شاه قاجار')['ruler']['name']);
        $this->assertSame('عباس صفوی دوم، شاه ایران', KnownRulers::find('شاه عباس ثانی')['ruler']['name']);
        $this->assertSame('قطب شاه، عبدالله بن محمد', KnownRulers::find('سلطان عبدالله قطبشاه')['ruler']['name']);
        $this->assertNull(KnownRulers::find('شاه عباس ثانی')['certainty']);
        foreach (['ظل السلطان', 'محمد شاه', 'عالمگیر'] as $ambiguous) {
            $this->assertNull(KnownRulers::find($ambiguous), $ambiguous);
        }
    }

    public function test_a_usual_spelling_is_decided_by_the_year_of_composition()
    {
        // No year: the usual one, kept as probable with the other possibility in the note
        $this->assertSame(['عباس صفوی، شاه ایران', 'probable', 'شاید شاه عباس دوم'], array_values(self::flat(KnownRulers::find('شاه عباس صفوی'))));
        // A year in the second Abbas's lifetime picks him, and is sure
        $this->assertSame(['عباس صفوی دوم، شاه ایران', null, null], array_values(self::flat(KnownRulers::find('شاه عباس صفوی', 1060))));
        // The field's own dates name the ruler, before the year of composition
        $this->assertSame(['عباس صفوی دوم، شاه ایران', null, null], array_values(self::flat(KnownRulers::find('شاه عباس صفوی', null, 1077))));
        $this->assertSame(['عباس صفوی، شاه ایران', null, null], array_values(self::flat(KnownRulers::find('شاه عباس', 1060, 1037))));
        // A year in neither lifetime: text
        $this->assertNull(KnownRulers::find('شاه عباس صفوی', 1040));
        // The first Tahmasp and the Qajar Mohammad Ali Shah are taken as they are, unless the year rules them out
        $this->assertSame(['طهماسب صفوی اول، شاه ایران', null, null], array_values(self::flat(KnownRulers::find('شاه طهماسب صفوی'))));
        $this->assertNull(KnownRulers::find('شاه طهماسب صفوی', 1140));
        $this->assertSame(['محمد علی قاجار، شاه ایران', null, null], array_values(self::flat(KnownRulers::find('محمد علی شاه'))));
        $this->assertNull(KnownRulers::find('محمد علی شاه', 1255));
    }

    private static function flat(array $found): array
    {
        return ['name' => $found['ruler']['name'], 'certainty' => $found['certainty'], 'note' => $found['note']];
    }
}
