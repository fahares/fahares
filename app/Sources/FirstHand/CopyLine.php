<?php

namespace App\Sources\FirstHand;

use App\Support\ScriptNameCleaner;

/**
 * What a library catalogue's own line for a copy says, field by field (backlog item 147, step 14.250): the values a
 * reader (App\Sources\FirstHand\Readers) took from the printed text, each with the words it took them from. A field
 * the line does not give is absent, not null; a collection's line that counts the whole volume («212 برگ» for seven
 * treatises) marks those counts whole_volume, so they are not read against one treatise.
 */
class CopyLine
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, string> */
    public array $raw = [];

    public bool $wholeVolume = false;

    public function set(string $field, mixed $value, string $raw): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }
        $this->values[$field] = $value;
        $this->raw[$field] = trim($raw);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    /** Persian and Arabic digits to Latin, «ي/ى/ك» to «ی/ک», runs of spaces folded. */
    public static function normalize(string $text): string
    {
        $text = strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ؤ' => 'و', "\u{200C}" => ' ', '‌' => ' ',
        ]);

        return trim(preg_replace('/[ \t]+/u', ' ', $text));
    }

    /** The known scripts a piece starts with: «نستعلیق شکسته»، «نسخ و نستعلیق» → their names, longest first. */
    public static function scriptsAt(string $piece): array
    {
        $piece = self::normalize($piece);
        $known = array_merge(ScriptNameCleaner::KNOWN_SCRIPTS, ScriptNameCleaner::SECOND_HANDS);
        usort($known, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $found = [];
        $rest = $piece;
        while ($rest !== '') {
            $hit = null;
            foreach ($known as $name) {
                $next = mb_substr($rest, mb_strlen($name), 1);
                if (str_starts_with($rest, $name) && ($next === '' || preg_match('/[\s،,.؛:()\-]/u', $next))) {
                    $hit = $name;
                    break;
                }
            }
            if ($hit === null) {
                break;
            }
            $found[] = $hit;
            $rest = ltrim(mb_substr($rest, mb_strlen($hit)));
            $rest = preg_replace('/^و\s+/u', '', $rest);
        }

        return $found;
    }

    /** «مؤلف» or «مترجم» for the scribe: the author's or translator's own hand. */
    public static function isAutographWord(string $word): bool
    {
        $word = preg_replace('/^(به\s*)?خط\s*/u', '', trim(self::normalize($word), " .\t"));

        return in_array($word, ['مولف', 'مترجم', 'مصنف'], true);
    }

    /** A piece that is a note, not a scribe's name: «نام کاتب پاک شده»، «خط خورده است»، «تمامی صفحات جدول شنگرف». */
    public static function isNote(string $piece): bool
    {
        return (bool) preg_match('/(?<!\p{L})(بدون کاتب|برای|حواشی|حسب|سال|است|شده|دارد|ندارد|جدول|شنگرف|عناوین|نشانی|حاشیه|نام کاتب|آیات|سده|قرن|تاریخ)(?!\p{L})/u', self::normalize($piece));
    }

    /** A piece that is a date: a Hijri year or a century, short, and not a note («در اول نسخه تملکی … در 1274»). */
    public static function isDate(string $piece): bool
    {
        $piece = self::normalize($piece);
        if (mb_strlen($piece) > 40 || preg_match('/نسخه|مهر|تملک|یادداشت|عنوان|شنگرف|برگ|سطر|سم(?!\p{L})|جلد/u', $piece)) {
            return false;
        }

        return (bool) preg_match('/\d{3,4}|قرن\s*\d/u', $piece);
    }

    /** The text after the leading script names: «نسخ محمد در 1084» → «محمد در 1084». */
    public static function afterScripts(string $piece): string
    {
        $piece = self::normalize($piece);
        foreach (self::scriptsAt($piece) as $name) {
            $piece = ltrim(mb_substr($piece, mb_strlen($name)));
            $piece = preg_replace('/^و\s+/u', '', $piece);
        }

        return trim($piece);
    }
}
