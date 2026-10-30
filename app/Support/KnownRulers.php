<?php

namespace App\Support;

use App\Helpers\TextNormalizer;

/**
 * Rulers and patrons the Fankha «اهدا / به درخواست» field names most often, each with the name of its
 * authority record in the people table («ناصرالدین قاجار، شاه ایران»), the Hijri death year and the spellings
 * the field uses. Spellings are compared without spaces («ناصر الدین شاه» = «ناصرالدین شاه»).
 * A spelling that fits more than one person («ظل السلطان» alone: two princes held the title; «محمد شاه»: Qajar or
 * Mughal; «عالمگیر» alone: the first or the second) is left out on purpose and stays text, unless it is in USUALLY.
 * The list was built from the field's values on 7 Aban 1405 (backlog item 35, step 2) and reviewed by the user.
 */
class KnownRulers
{
    public const LIST = [
        // Qajar
        ['name' => 'ناصرالدین قاجار، شاه ایران', 'death' => 1313, 'spellings' => ['ناصرالدین شاه', 'ناصرالدین شاه قاجار', 'ناصرالدین قاجار', 'سلطان ناصرالدین شاه', 'ناصرالدین شاه غازی']],
        ['name' => 'مظفرالدین قاجار، شاه ایران', 'death' => 1324, 'spellings' => ['مظفرالدین شاه', 'مظفرالدین شاه قاجار', 'مظفرالدین میرزای ولیعهد', 'مظفرالدین میرزا ولیعهد']],
        ['name' => 'فتحعلی شاه قاجار، شاه ایران', 'death' => 1250, 'spellings' => ['فتحعلی شاه', 'فتحعلی شاه قاجار', 'فتحعلیشاه', 'فتحعلیشاه قاجار']],
        ['name' => 'محمد شاه قاجار', 'death' => 1264, 'spellings' => ['محمد شاه قاجار', 'سلطان محمد شاه قاجار', 'محمد شاه غازی قاجار']],
        ['name' => 'محمد علی قاجار، شاه ایران', 'death' => 1343, 'spellings' => ['محمد علی شاه قاجار', 'محمدعلی شاه قاجار']],
        ['name' => 'عباس میرزا قاجار', 'death' => 1249, 'spellings' => ['عباس میرزا نایب السلطنه', 'عباس میرزا ولیعهد', 'نایب السلطنه عباس میرزا', 'عباس میرزا قاجار']],
        ['name' => 'ظل السلطان، مسعود میرزا بن ناصر', 'death' => 1336, 'spellings' => ['ظل السلطان مسعود میرزا', 'مسعود میرزا ظل السلطان', 'سلطان مسعود میرزا ظل السلطان']],
        ['name' => 'اعتضادالسلطنه، علیقلی بن فتحعلی', 'death' => 1298, 'spellings' => ['علیقلی میرزا اعتضاد السلطنه', 'اعتضاد السلطنه علیقلی میرزا']],
        ['name' => 'امین السلطان، علی اصغر بن ابراهیم', 'death' => 1325, 'spellings' => ['میرزا علی اصغر خان اتابک', 'میرزا علی اصغر خان امین السلطان', 'اتابک اعظم میرزا علی اصغر خان', 'علی اصغر خان اتابک']],
        ['name' => 'حاج میرزا آقاسی، عباس بن مسلم', 'death' => 1265, 'spellings' => ['حاجی میرزا آقاسی', 'حاج میرزا آقاسی']],
        // Zand
        ['name' => 'کریم خان زند، شاه ایران', 'death' => 1193, 'spellings' => ['کریم خان زند', 'کریمخان زند', 'محمد کریم خان زند', 'وکیل الرعایا کریم خان زند']],
        // Safavid
        ['name' => 'اسماعیل صفوی اول، شاه ایران', 'death' => 930, 'spellings' => ['شاه اسماعیل اول', 'شاه اسماعیل اول صفوی']],
        ['name' => 'طهماسب صفوی اول، شاه ایران', 'death' => 984, 'spellings' => ['شاه طهماسب اول', 'شاه تهماسب اول', 'شاه طهماسب اول صفوی', 'شاه تهماسب اول صفوی']],
        ['name' => 'عباس صفوی، شاه ایران', 'death' => 1038, 'spellings' => ['شاه عباس اول', 'شاه عباس اول صفوی', 'شاه عباس کبیر']],
        ['name' => 'صفی صفوی، شاه ایران', 'death' => 1052, 'spellings' => ['شاه صفی', 'شاه صفی صفوی']],
        ['name' => 'عباس صفوی دوم، شاه ایران', 'death' => 1077, 'spellings' => ['شاه عباس دوم', 'شاه عباس دوم صفوی', 'شاه عباس صفوی دوم', 'شاه عباس ثانی', 'شاه عباس ثانی صفوی']],
        ['name' => 'سلیمان صفوی اول، شاه ایران', 'death' => 1105, 'spellings' => ['شاه سلیمان', 'شاه سلیمان صفوی', 'سلطان سلیمان صفوی', 'شاه سلطان سلیمان صفوی', 'شاه سلیمان اول صفوی']],
        ['name' => 'حسین صفوی، شاه ایران', 'death' => 1135, 'spellings' => ['شاه سلطان حسین', 'شاه سلطان حسین صفوی', 'سلطان حسین صفوی', 'شاه سلطان حسین موسوی صفوی']],
        ['name' => 'دشتکی شیرازی، منصور بن صدرالدین محمد', 'death' => 948, 'spellings' => ['غیاث الدین منصور دشتکی', 'غیاث الدین منصور دشتکی شیرازی', 'امیر غیاث الدین منصور دشتکی']],
        // Seljuk
        ['name' => 'سنجر سلجوقی، سلطان', 'death' => 552, 'spellings' => ['سلطان سنجر', 'سلطان سنجر سلجوقی', 'سنجر بن ملکشاه']],
        // Timurid
        ['name' => 'حسین بایقرا', 'death' => 911, 'spellings' => ['سلطان حسین میرزا بایقرا', 'سلطان حسین بایقرا', 'حسین بایقرا', 'سلطان حسین بایقرا میرزا']],
        ['name' => 'امیر علیشیر نوائی، علیشیر بن کیچکنه', 'death' => 906, 'spellings' => ['امیر علیشیر نوائی', 'امیر علیشیر نوایی', 'علیشیر نوائی', 'علیشیر نوایی', 'امیر علیشیر']],
        ['name' => 'میرزا الغ بیگ', 'death' => 853, 'spellings' => ['الغ بیگ', 'میرزا الغ بیگ', 'الغ بیگ پسر شاهرخ پسر تیمور گورکان', 'الغ بیگ گورکان']],
        // Mughal
        ['name' => 'بابر، امپراتور هند', 'death' => 937, 'spellings' => ['ظهیرالدین محمد بابر پادشاه', 'ظهیرالدین محمد بابر', 'بابر پادشاه']],
        ['name' => 'همایون بابری، پادشاه هند', 'death' => 963, 'spellings' => ['همایون پادشاه', 'همایونشاه بابری', 'نصیرالدین محمد همایون']],
        ['name' => 'جهانگیر بابری، پادشاه هند', 'death' => 1037, 'spellings' => ['جهانگیر پادشاه', 'جهانگیر پادشاه بابری هند', 'نورالدین محمد جهانگیر پادشاه', 'نورالدین جهانگیر', 'شهنشاه جهانگیر']],
        ['name' => 'شاه جهان بابری، شاه هند', 'death' => 1076, 'spellings' => ['شاه جهان', 'ابوالمظفر شهاب الدین محمد شاه جهان', 'شهاب الدین محمد شاه جهان', 'شاهجهان پادشاه']],
        ['name' => 'اورنگ زیب بابری، امپراتور هند', 'death' => 1118, 'spellings' => ['اورنگ زیب', 'اورنگ زیب عالمگیر', 'محمد اورنگ زیب', 'محیی الدین محمد اورنگ زیب عالمگیر', 'محی الدین محمد اورنگ زیب', 'محیی الدین محمد اورنگ زیب', 'محی الدین محمد اورنگ زیب عالمگیر']],
        // Qutb Shahi
        ['name' => 'قطب شاه، محمد قلی بن ابراهیم', 'death' => 1020, 'spellings' => ['محمد قلی قطب شاه', 'سلطان محمد قلی قطب شاه']],
        ['name' => 'قطب شاه، محمد بن محمد قلی', 'death' => 1035, 'spellings' => ['سلطان محمد قطب شاه']],
        ['name' => 'قطب شاه، عبدالله بن محمد', 'death' => 1083, 'spellings' => ['عبدالله قطب شاه', 'سلطان عبدالله قطب شاه']],
        ['name' => 'قطب شاه، ابراهیم بن قلی', 'death' => 988, 'spellings' => ['سلطان ابراهیم قطب شاه', 'ابراهیم قطب شاه']],
        // Ottoman and Ilkhanid ministers
        ['name' => 'بایزید دوم عثمانی، سلطان', 'death' => 918, 'spellings' => ['سلطان بایزید بن محمد خان', 'سلطان بایزید خان بن محمد خان', 'بایزید دوم']],
        ['name' => 'جوینی، محمد بن محمد', 'death' => 683, 'spellings' => ['شمس الدین محمد بن بهاءالدین محمد جوینی', 'شمس الدین محمد جوینی', 'خواجه شمس الدین محمد جوینی', 'شمس الدین محمد صاحب دیوان', 'خواجه شمس الدین محمد صاحب دیوان جوینی']],
        ['name' => 'جوینی، بهاءالدین محمد بن محمد', 'death' => 678, 'spellings' => ['بهاءالدین محمد بن شمس الدین محمد جوینی', 'بهاءالدین محمد جوینی']],
    ];

    /**
     * Spellings that name more than one ruler but mostly one (decided by the user, 8 Aban 1405). The work's year of
     * composition, when known, picks the ruler whose lifetime holds it; outside every lifetime the name stays text.
     * With no year the usual one is taken, and when «doubt» is set the row is only probable and the note keeps the
     * other possibility.
     */
    public const USUALLY = [
        ['spellings' => ['شاه عباس صفوی', 'شاه عباس', 'عباس شاه صفوی'], 'doubt' => 'شاید شاه عباس دوم', 'rulers' => [
            ['name' => 'عباس صفوی، شاه ایران', 'lived' => [978, 1038]],
            ['name' => 'عباس صفوی دوم، شاه ایران', 'lived' => [1042, 1077]],
        ]],
        ['spellings' => ['شاه طهماسب صفوی', 'شاه تهماسب صفوی', 'شاه طهماسب', 'شاه تهماسب'], 'doubt' => null, 'rulers' => [
            ['name' => 'طهماسب صفوی اول، شاه ایران', 'lived' => [919, 984]],
        ]],
        ['spellings' => ['محمد علی شاه', 'محمدعلی شاه'], 'doubt' => null, 'rulers' => [
            ['name' => 'محمد علی قاجار، شاه ایران', 'lived' => [1289, 1343]],
        ]],
    ];

    /** @var array<string, array{0: string, 1: int}>|null compact spelling => [LIST or USUALLY, index] */
    private static ?array $bySpelling = null;

    /**
     * The ruler a piece of the field names, given the work's year of composition: the LIST entry with the row's
     * certainty and note, or null.
     *
     * @return array{ruler: array, certainty: ?string, note: ?string}|null
     */
    public static function find(string $piece, ?int $year = null): ?array
    {
        if (self::$bySpelling === null) {
            self::$bySpelling = [];
            foreach (self::LIST as $i => $ruler) {
                foreach (array_merge([$ruler['name']], $ruler['spellings']) as $spelling) {
                    self::$bySpelling[self::compact($spelling)] = ['list', $i];
                }
            }
            foreach (self::USUALLY as $i => $group) {
                foreach ($group['spellings'] as $spelling) {
                    self::$bySpelling[self::compact($spelling)] = ['usually', $i];
                }
            }
        }
        [$kind, $i] = self::$bySpelling[self::compact($piece)] ?? [null, null];
        if ($kind === 'list') {
            return ['ruler' => self::LIST[$i], 'certainty' => null, 'note' => null];
        }
        if ($kind !== 'usually') {
            return null;
        }
        $group = self::USUALLY[$i];
        if ($year) {
            foreach ($group['rulers'] as $candidate) {
                if ($year >= $candidate['lived'][0] && $year <= $candidate['lived'][1]) {
                    return ['ruler' => self::named($candidate['name']), 'certainty' => null, 'note' => null];
                }
            }

            return null;
        }

        return ['ruler' => self::named($group['rulers'][0]['name']), 'certainty' => $group['doubt'] ? 'probable' : null, 'note' => $group['doubt']];
    }

    private static function named(string $name): array
    {
        foreach (self::LIST as $ruler) {
            if ($ruler['name'] === $name) {
                return $ruler;
            }
        }
        throw new \LogicException("KnownRulers::USUALLY names «{$name}», which is not in LIST.");
    }

    public static function compact(string $text): string
    {
        return str_replace([' ', '،', '-'], '', TextNormalizer::normalize($text));
    }
}
