<?php

namespace App\Support;

/**
 * Persian number display: «۱۲٬۳۴۵» instead of «12,345».
 */
class Fa
{
    private const DIGITS = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];

    public static function digits(string|int|null $text): string
    {
        return strtr((string) $text, self::DIGITS);
    }

    /** Thousands grouped with the Persian separator «٬». */
    public static function num(int|float|null $n): string
    {
        return self::digits(str_replace(',', '٬', number_format((float) $n)));
    }

    /** Rounded down for running text: 71516 → «۷۱ هزار». Below 10,000 the exact number is shown. */
    public static function approx(int $n): string
    {
        if ($n < 10000) {
            return self::num($n);
        }

        return self::digits((string) intdiv($n, 1000)) . ' هزار';
    }
}
