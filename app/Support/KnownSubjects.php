<?php

namespace App\Support;

/**
 * The people a work's title names as its subject (backlog item 35, the subject role): «مناقب امیرالمؤمنین»,
 * «مقتل الحسین», «شرح حال پیامبر». A title that starts with one of WORDS is read as the word and its subject; the
 * subject is cut on «و» («پیامبر و ائمه»), and each piece without its honorifics («(ع)», «علیه السلام») is compared
 * without spaces with the spellings of LIST: the Prophet, Fatima and the twelve Imams, each with the name of their
 * authority record. A piece that names a group («ائمه», «چهارده معصوم») or anyone else is not linked here; the others
 * go to a list the user reviews (fahares:backfill-work-subjects --candidates), and those approved are applied from a
 * decision file. The list was decided by the user on 11 Aban 1405, with «مصطفی» for the Prophet and «علی», «علوی» and «مرتضوی» for
 * Imam Ali, which after these title words name no one else.
 */
class KnownSubjects
{
    /** Title words that make what follows the work's subject, and the forms they take. */
    public const WORDS = 'شرح\s*حال(?:ات)?|ترجمه\s*حال|احوال(?:ات)?|مناقب|فضائل|فضایل|مقتل|مدح|مدیحه|مدایح|مرثیه|مراثی|رثاء|سیرت|سیره|سیرة|زندگینامه|زندگی\s*نامه|منقبت|وفات|ولادت|مولود|نعت';

    public const LIST = [
        ['name' => 'محمد (ص)، پیامبر اسلام', 'spellings' => ['النبی', 'پیامبر', 'پیامبر اسلام', 'پیغمبر', 'پیغمبر اسلام', 'حضرت رسول', 'رسول', 'الرسول', 'رسول الله', 'رسول خدا', 'رسول اکرم', 'حضرت محمد', 'محمد مصطفی', 'حضرت محمد مصطفی', 'سید المرسلین', 'خاتم الانبیاء', 'خاتم النبیین', 'نبی اکرم', 'نبی', 'النبی المصطفی', 'المصطفی', 'مصطفی']],
        ['name' => 'فاطمه زهرا (س)', 'spellings' => ['فاطمه', 'فاطمه زهرا', 'حضرت فاطمه', 'حضرت زهرا', 'فاطمة الزهراء', 'فاطمة', 'الزهراء', 'حضرت فاطمه زهرا']],
        ['name' => 'علی بن ابی طالب (ع)، امام اول', 'spellings' => ['امیرالمؤمنین', 'امیر المؤمنین', 'امیرالمؤمنین علی', 'امیرالمؤمنین علی بن ابی طالب', 'علی بن ابی طالب', 'علی بن ابیطالب', 'امام علی', 'الامام علی', 'حضرت علی', 'حضرت امیر', 'مولا علی', 'مولی علی', 'علی مرتضی', 'مرتضی علی', 'الامام علی بن ابی طالب', 'امام علی بن ابی طالب', 'حضرت امیرالمؤمنین', 'شاه ولایت', 'علی', 'علوی', 'مرتضوی', 'المرتضوی']],
        ['name' => 'حسن بن علی (ع)، امام دوم', 'spellings' => ['امام حسن', 'امام حسن مجتبی', 'حسن مجتبی', 'الامام الحسن', 'الحسن بن علی', 'حضرت امام حسن', 'امام حسن بن علی']],
        ['name' => 'حسین بن علی (ع)، امام سوم', 'spellings' => ['امام حسین', 'الحسین', 'حسین', 'حسین بن علی', 'الحسین بن علی', 'الامام الحسین', 'حضرت امام حسین', 'سید الشهداء', 'سیدالشهداء', 'حضرت سیدالشهداء', 'حضرت سید الشهداء', 'ابی عبدالله الحسین', 'ابا عبدالله الحسین', 'اباعبدالله الحسین', 'امام حسین بن علی', 'الامام الحسین بن علی']],
        ['name' => 'علی بن حسین (ع)، امام چهارم', 'spellings' => ['امام سجاد', 'امام زین العابدین', 'زین العابدین', 'علی بن الحسین', 'علی بن الحسین زین العابدین', 'الامام السجاد', 'السجاد']],
        ['name' => 'محمد بن علی (ع)، امام پنجم', 'spellings' => ['امام باقر', 'امام محمد باقر', 'الامام الباقر', 'محمد بن علی الباقر', 'الباقر']],
        ['name' => 'جعفر بن محمد (ع)، امام ششم', 'spellings' => ['امام صادق', 'امام جعفر صادق', 'الامام الصادق', 'جعفر بن محمد الصادق', 'جعفر صادق', 'الصادق']],
        ['name' => 'موسی بن جعفر (ع)، امام هفتم', 'spellings' => ['امام کاظم', 'امام موسی کاظم', 'الامام الکاظم', 'موسی بن جعفر', 'موسی بن جعفر الکاظم', 'الکاظم']],
        ['name' => 'علی بن موسی (ع)، امام هشتم', 'spellings' => ['امام رضا', 'امام هشتم', 'الامام الرضا', 'علی بن موسی الرضا', 'علی بن موسی', 'حضرت رضا', 'الرضا', 'امام علی بن موسی الرضا', 'حضرت امام رضا']],
        ['name' => 'محمد بن علی (ع)، امام نهم', 'spellings' => ['امام جواد', 'امام محمد تقی', 'امام محمد جواد', 'الامام الجواد', 'محمد بن علی الجواد', 'الجواد']],
        ['name' => 'علی بن محمد، امام دهم', 'spellings' => ['امام هادی', 'امام علی النقی', 'امام علی نقی', 'الامام الهادی', 'علی بن محمد الهادی', 'الهادی']],
        ['name' => 'حسن بن علی (ع)، امام یازدهم', 'spellings' => ['امام حسن عسکری', 'الامام العسکری', 'الحسن بن علی العسکری', 'حسن عسکری', 'العسکری']],
        ['name' => 'محمد بن حسن (عج)، امام دوازدهم', 'spellings' => ['امام زمان', 'حضرت قائم', 'القائم', 'صاحب الزمان', 'حضرت صاحب الزمان', 'حضرت حجت', 'امام مهدی', 'المهدی', 'مهدی موعود', 'حجة بن الحسن', 'حجت بن الحسن', 'امام دوازدهم', 'صاحب الامر', 'حضرت صاحب الامر']],
    ];

    /** @var array<string, string>|null compact spelling => record name */
    private static ?array $bySpelling = null;

    /**
     * The title's word and its subject pieces: «مقتل الحسین (ع)» is ['مقتل', ['الحسین']]; null when the title
     * starts with none of WORDS.
     *
     * @return array{0: string, 1: string[]}|null
     */
    public static function fromTitle(?string $title): ?array
    {
        if (! preg_match('/^(' . self::WORDS . ')\s+(.+)$/u', trim((string) $title), $m)) {
            return null;
        }
        $pieces = [];
        foreach (preg_split('/\s+و\s+/u', $m[2]) as $piece) {
            $piece = preg_replace(['/\((?:ع|ص|س|عج|ره|رض|قده)\)/u', '/(?:علیه|علیها|علیهم|عليه|عليها|عليهم)\s*(?:السلام|الصلاة\s*والسلام)/u',
                '/صلی\s*الله\s*علیه\s*(?:و\s*)?آله(?:\s*و\s*سلم)?/u', '/\s*\([^()]*\)\s*$/u'], ' ', $piece);
            // trim() would cut «،» byte by byte, and with it the first byte of «ا»
            $piece = preg_replace(['/\s+/u', '/^[\s.،:]+|[\s.،:]+$/u'], [' ', ''], $piece);
            if ($piece !== '') {
                $pieces[] = $piece;
            }
        }

        return [preg_replace('/\s+/u', ' ', $m[1]), $pieces];
    }

    /** The authority record name a piece names, or null. */
    public static function find(string $piece): ?string
    {
        if (self::$bySpelling === null) {
            self::$bySpelling = [];
            foreach (self::LIST as $subject) {
                foreach ($subject['spellings'] as $spelling) {
                    self::$bySpelling[KnownRulers::compact($spelling)] = $subject['name'];
                }
            }
        }

        return self::$bySpelling[KnownRulers::compact($piece)] ?? null;
    }
}
