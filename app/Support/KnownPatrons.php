<?php

namespace App\Support;

/**
 * Titles and short names under which catalogues name the patron of a copy («ظل السلطان», «آیة الله بروجردی»),
 * each with the person who held it in which years, by the name of their record in the people table
 * (step 14.120). A title held by more than one person in turn («رکن الدوله»: Ardashir Mirza, then Mohammad Taqi
 * Mirza) is decided by the copy's year: only the listed holder's years link. A copy with no year links only when
 * the title had one holder ever; `shared` marks a title others held too, outside the list («بهاء الدوله» the Buyid). A holder with a death year gets a record of that name when the people table has
 * none. Spellings are compared as in KnownRulers, without spaces. The list was reviewed by the user.
 */
class KnownPatrons
{
    public const LIST = [
        ['spellings' => ['آیة الله بروجردی', 'آیت الله بروجردی', 'آیة الله حاج آقا حسین طباطبائی بروجردی', 'آیة الله حاج آقا حسین طباطبایی بروجردی',
            'حاج آقا حسین طباطبائی بروجردی', 'حاج آقا حسین طباطبایی بروجردی', 'آیة الله العظمی بروجردی', 'آیة الله العظمی حاج آقا حسین طباطبائی بروجردی'],
            'holders' => [['name' => 'طباطبائی بروجردی، حسین بن علی', 'from' => 1320, 'to' => 1380, 'death' => 1380]]],
        ['spellings' => ['ظل السلطان', 'ظل‌السلطان'],
            'shared' => true, 'holders' => [['name' => 'ظل السلطان، مسعود میرزا بن ناصر', 'from' => 1286, 'to' => 1336]]],
        ['spellings' => ['فرهاد میرزا', 'حاجی فرهاد میرزا', 'حاج فرهاد میرزا', 'معتمد الدوله فرهاد میرزا', 'فرهاد میرزا معتمد الدوله', 'حاج فرهاد میرزا معتمد الدوله', 'شاهزاده فرهاد میرزا'],
            'holders' => [['name' => 'فرهاد میرزا قاجار', 'from' => 1245, 'to' => 1305]]],
        ['spellings' => ['رکن الدوله', 'رکن الدوله فرمانفرمای خراسان و سیستان', 'شاهزاده رکن الدوله', 'محمد تقی میرزا رکن الدوله'],
            'shared' => true, 'holders' => [['name' => 'رکن الدوله، محمد تقی', 'from' => 1287, 'to' => 1318]]],
        ['spellings' => ['بهاء الدوله', 'شاهزاده بهاء الدوله', 'بهاء الدوله بهمن میرزا', 'بهاء الدوله بهمن میرزا پسر فتحعلی شاه', 'بهمن میرزا بهاء الدوله'],
            'shared' => true, 'holders' => [['name' => 'بهمن میرزا قاجار', 'from' => 1250, 'to' => 1301]]],
        ['spellings' => ['اعتضاد السلطنه', 'اعتضاد السلطنة'],
            'holders' => [['name' => 'اعتضادالسلطنه، علیقلی بن فتحعلی', 'from' => 1255, 'to' => 1298]]],
        ['spellings' => ['علامه مجلسی', 'مجلسی دوم', 'علامه مجلسی محمد باقر', 'ملا محمد باقر مجلسی', 'محمد باقر مجلسی'],
            'holders' => [['name' => 'مجلسی، محمد باقر بن محمد تقی', 'from' => 1050, 'to' => 1110]]],
        ['spellings' => ['محمد علم الهدی ابن فیض کاشانی', 'مولی محمد علم الهدی ابن فیض کاشانی', 'محمد علم الهدی بن فیض کاشانی', 'علم الهدی بن فیض کاشانی',
            'علم الهدی پسر فیض کاشانی', 'علم الهدی فرزند فیض کاشانی', 'محمد علم الهدی کاشانی'],
            'holders' => [['name' => 'علم الهدی، محمد بن محسن', 'from' => 1055, 'to' => 1115]]],
        ['spellings' => ['حاج میرزا حسین نوری', 'میرزا حسین نوری', 'حاجی میرزا حسین نوری', 'محدث نوری'],
            'holders' => [['name' => 'نوری، حسین بن محمد تقی', 'from' => 1270, 'to' => 1320]]],
        ['spellings' => ['عزالدوله عبدالصمد میرزا', 'عبدالصمد میرزا عزالدوله', 'شاهزاده عزالدوله', 'عز الدوله عبدالصمد میرزا'],
            'shared' => true, 'holders' => [['name' => 'عزالدوله، عبدالصمد بن محمد', 'from' => 1270, 'to' => 1347, 'death' => 1347]]],
        ['spellings' => ['محمد ولی میرزا', 'شاهزاده محمد ولی میرزا'],
            'shared' => true, 'holders' => [['name' => 'محمد ولی میرزا', 'from' => 1210, 'to' => 1279]]],
        ['spellings' => ['احتشام الدوله', 'خانلر میرزا احتشام الدوله', 'خانلر میرزای احتشام الدوله'],
            'shared' => true, 'holders' => [['name' => 'احتشام الدوله', 'from' => 1250, 'to' => 1278]]],
        ['spellings' => ['ابراهیم بن قطب شاه', 'ابراهیم قطب شاه'],
            'holders' => [['name' => 'قطب شاه، ابراهیم بن قلی', 'from' => 950, 'to' => 988]]],
    ];

    /** Words before the name that say how the copy came to the patron: «کتابخانه», «تقدیم به», «سرکار»… */
    private const LEAD = '/^(کتابخانه(ی|\x{200C}ی)?|تقدیم به|پیشکش به|برای|حضرت|سرکار|جناب|نواب|به نام)\s+/u';

    /** @var array<string, int>|null compact spelling => LIST index */
    private static ?array $bySpelling = null;

    /**
     * The holder of the title in this year, or null.
     *
     * @return array{name: string, from: int, to: int, death?: int}|null
     */
    public static function find(string $name, ?int $year): ?array
    {
        $i = self::index($name);
        if ($i === null) {
            return null;
        }
        $holders = self::LIST[$i]['holders'];
        if ($year === null) {
            return count($holders) === 1 && empty(self::LIST[$i]['shared']) ? $holders[0] : null;
        }
        foreach ($holders as $holder) {
            if ($year >= $holder['from'] && $year <= $holder['to']) {
                return $holder;
            }
        }

        return null;
    }

    /** Whether the name is one of the list's spellings, whoever held it in the copy's year. */
    public static function knows(string $name): bool
    {
        return self::index($name) !== null;
    }

    private static function index(string $name): ?int
    {
        if (self::$bySpelling === null) {
            self::$bySpelling = [];
            foreach (self::LIST as $i => $entry) {
                foreach ($entry['spellings'] as $spelling) {
                    self::$bySpelling[KnownRulers::compact($spelling)] = $i;
                }
            }
        }

        return self::$bySpelling[KnownRulers::compact(self::core($name))] ?? null;
    }

    /** The name without the words before it that are not part of it: «کتابخانه سرکار ظل السلطان» → «ظل السلطان» */
    public static function core(string $name): string
    {
        $name = trim($name);
        do {
            $before = $name;
            $name = trim(preg_replace(self::LEAD, '', $name));
        } while ($name !== $before);

        return $name;
    }
}
