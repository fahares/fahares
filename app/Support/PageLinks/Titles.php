<?php

namespace App\Support\PageLinks;

use App\Console\Commands\MatchCatalogEditionCommand;
use App\Helpers\TextNormalizer;

/**
 * Whether two titles name one work, for the links of a page's text (step 13.155): the letters made one, «ال» and the
 * form's word in front («مثنوي»، «ديوان»، «رسالة») dropped, the same, one the start of the other (of two words or more),
 * or nearly the same (difflib's ratio 0.9). «روضة الواعظين» is «روضة الواعظین و تبصرة المتعظین»; «شرح …», «ترجمة …» (MADE_ON)
 * is not the work it holds.
 */
class Titles
{
    private const FORMS = ['مثنوی', 'دیوان', 'رساله', 'کتاب', 'منظومه'];

    /** Words that make a title another work's made on the one it holds: «شرح خلاصه الاحکام» is not «خلاصه الاحکام». */
    private const MADE_ON = ['شرح', 'حاشیه', 'حواشی', 'ترجمه', 'تعلیقه', 'تعلیقات', 'تلخیص', 'مختصر', 'منتخب', 'تکمله', 'ذیل', 'نقد', 'رد', 'نظم', 'تخمیس', 'مقدمه', 'دیباچه'];

    public static function key(?string $title): string
    {
        $key = MatchCatalogEditionCommand::norm(TextNormalizer::normalize((string) $title));

        // «رسالة في القضاء و القدر» is «القضاء و القدر»
        return preg_replace('/^(?:(?:' . implode('|', self::FORMS) . ') (?:فی |در )?)(?=\S)/u', '', $key);
    }

    public static function agree(?string $a, ?string $b): bool
    {
        $a = self::key($a);
        $b = self::key($b);
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }
        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
        if (in_array(strtok($long, ' '), self::MADE_ON, true) && strtok($short, ' ') !== strtok($long, ' ')) {
            return false;
        }

        // one the start of the other, a title and its subtitle («زاد المسافر و لهنة المقيم و المسافر»), of two words or
        // more: «نوربخش», «دیوان نوربخش» without its form, is in many titles, and «ملاحظات حول ديوان ناصر خسرو» or «من
        // کلام ثیاذوق فی حفظ الصحة» are not the work they end with
        return (str_contains($short, ' ') && mb_strlen($short) >= 6 && str_starts_with("{$long} ", "{$short} "))
            // nearly the same, of six letters or more: at 0.8 «لالی» was «جلالیه» and «فرح نامه» «فرخ نامه»
            || (mb_strlen($short) >= 6 && MatchCatalogEditionCommand::similarity($a, $b) >= 0.9);
    }

    /** Whether a title is in a text: its key, of two words or of six letters or more, among the text's words. */
    public static function in(?string $title, string $text): bool
    {
        $key = self::key($title);
        if ($key === '' || (! str_contains($key, ' ') && mb_strlen($key) < 6)) {
            return false;
        }

        return str_contains(' ' . MatchCatalogEditionCommand::norm(TextNormalizer::normalize($text)) . ' ', " {$key} ");
    }
}
