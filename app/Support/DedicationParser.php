<?php

namespace App\Support;

/**
 * Splits the Fankha «اهدا / به درخواست» field (works.dedication) into the people it names, each with a role:
 * commissioner after «به درخواست»، «به خواهش»، «به سفارش»، «به دستور»، «به فرمان»، «به امر»، «حسب الامر»…, and
 * dedicatee after «به نام»، «برای»، «تقدیم»، «اهدا» or with no marker, since the field is first of all the dedication.
 * People are separated by «؛» and « و »; a piece with no marker of its own keeps the role of the one before it
 * («به درخواست الف و ب»: both commissioners). Parentheses go to the note («(1264-1313ق)», «(= … بزمان ولیعهدی)»),
 * except a hedge word, which goes to hedge with «شاید»، «ظاهراً»… at the start and «؟» at the end.
 */
class DedicationParser
{
    // «حسب‌الامر» and «به‌نام» are written with a zero-width non-joiner too
    private const COMMISSIONER = '/^(?:بنا[\s\x{200c}]+(?:به|بر)[\s\x{200c}]+|به[\s\x{200c}]+|حسب[\s\x{200c}]*(?:ال)?)(?:درخواست|خواهش|خواست|سفارش|دستور|فرمان|فرمایش|امر|اشاره|تشویق)(?=[\s\x{200c}:]|$)[\s\x{200c}]*:?\s*/u';

    private const DEDICATEE = '/^(?:برای(?=[\s\x{200c}])|به[\s\x{200c}]+نام|بنام(?=\s)|تقدیم(?:[\s\x{200c}]+(?:شده[\s\x{200c}]+)?به)?(?=[\s\x{200c}:])|اهداء?(?:[\s\x{200c}]+(?:شده[\s\x{200c}]+)?به)?(?=[\s\x{200c}:]))[\s\x{200c}]*:?\s*/u';

    private const HEDGE_WORDS = 'شاید|ظاهرا|ظاهراً|گویا|احتمالا|احتمالاً|محتملا|محتملاً';

    /**
     * @return array<int, array{role: string, name: string, note: ?string, hedge: ?string, death: ?int}>
     */
    public static function parse(?string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        if ($text === '') {
            return [];
        }
        $people = [];
        foreach (preg_split('/\s*؛\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $segment) {
            $role = 'dedicatee';
            foreach (self::pieces($segment) as $piece) {
                if (preg_match(self::COMMISSIONER, $piece, $m)) {
                    $role = 'commissioner';
                    $piece = mb_substr($piece, mb_strlen($m[0]));
                } elseif (preg_match(self::DEDICATEE, $piece, $m)) {
                    $role = 'dedicatee';
                    $piece = mb_substr($piece, mb_strlen($m[0]));
                }
                if ($person = self::person($piece)) {
                    $people[] = ['role' => $role] + $person;
                }
            }
        }

        return $people;
    }

    /** The segment split on « و », outside parentheses. */
    private static function pieces(string $segment): array
    {
        $pieces = [];
        $depth = 0;
        $current = '';
        foreach (preg_split('/(\(|\)|\sو\s)/u', $segment, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if ($part === '(') {
                $depth++;
            } elseif ($part === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && preg_match('/^\sو\s$/u', $part)) {
                $pieces[] = $current;
                $current = '';

                continue;
            }
            $current .= $part;
        }
        $pieces[] = $current;

        return array_values(array_filter(array_map('trim', $pieces), fn ($p) => $p !== ''));
    }

    /** @return array{name: string, note: ?string, hedge: ?string, death: ?int}|null */
    private static function person(string $piece): ?array
    {
        $notes = [];
        $hedge = null;
        $death = null;
        // Parentheses, and one the catalogue left open at the end
        $piece = preg_replace_callback('/\s*\(([^()]*)(?:\)|$)/u', function ($m) use (&$notes, &$hedge, &$death) {
            $inner = trim($m[1]);
            if (preg_match('/^(?:' . self::HEDGE_WORDS . '|؟)$/u', $inner)) {
                $hedge = $inner;
            } elseif ($inner !== '') {
                $notes[] = $inner;
                $digits = strtr($inner, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
                // «1264-1313ق», «-968ق»: the year after the dash is the death
                if (preg_match('/(?:^|\d)\s*-\s*(\d{3,4})\s*(?:ق|ه|هـ)?\.?\s*(?:ق)?$/u', $digits, $d)) {
                    $death = (int) $d[1];
                }
            }

            return '';
        }, $piece);
        // trim() works on bytes and would cut into Arabic letters that share a byte with «،»
        $piece = preg_replace('/^[\s.:،]+|[\s.:،]+$/u', '', $piece);
        if (preg_match('/^(' . self::HEDGE_WORDS . ')\s+/u', $piece, $m)) {
            $hedge = $m[1];
            $piece = trim(mb_substr($piece, mb_strlen($m[0])));
        }
        if (preg_match('/\s*\(?؟\)?$/u', $piece) && str_contains($piece, '؟')) {
            $hedge ??= '؟';
            $piece = trim(preg_replace('/\s*\(?؟\)?$/u', '', $piece));
        }
        if ($piece === '') {
            return null;
        }

        return ['name' => $piece, 'note' => $notes ? implode('؛ ', $notes) : null, 'hedge' => $hedge, 'death' => $death];
    }
}
