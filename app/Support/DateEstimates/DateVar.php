<?php

namespace App\Support\DateEstimates;

/**
 * One unknown date as one integer, so the engine's lists stay lists of numbers: a person's birth or death, a work's
 * composition, a copy's date.
 */
final class DateVar
{
    public const BIRTH = 0;

    public const DEATH = 1;

    public const COMPOSITION = 2;

    public const COPY = 3;

    public const FIELDS = [self::BIRTH => 'birth', self::DEATH => 'death', self::COMPOSITION => 'composition', self::COPY => 'copy'];

    public const TYPES = [self::BIRTH => 'person', self::DEATH => 'person', self::COMPOSITION => 'work', self::COPY => 'manuscript'];

    public static function of(int $kind, int $id): int
    {
        return $id * 4 + $kind;
    }

    public static function kind(int $var): int
    {
        return $var % 4;
    }

    public static function id(int $var): int
    {
        return intdiv($var, 4);
    }

    public static function field(int $var): string
    {
        return self::FIELDS[$var % 4];
    }

    public static function type(int $var): string
    {
        return self::TYPES[$var % 4];
    }
}
