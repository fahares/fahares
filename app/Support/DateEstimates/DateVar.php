<?php

namespace App\Support\DateEstimates;

/**
 * One unknown date as one integer, so the engine's lists stay lists of numbers: a person's birth or death, a work's
 * composition, a copy's date.
 *
 * A composition or a copying that lasted from one year to another (DateSpan::SPAN) is two dates, its start and its
 * end (step 14.222). The start keeps the date's own number, so the keys of the conflicts there are do not change; the
 * end is end() of it, a negative number. Both name the same record and the same field.
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

    /** The end of a date that lasted: another number for the same record and field. */
    public static function end(int $var): int
    {
        return -$var - 1;
    }

    public static function isEnd(int $var): bool
    {
        return $var < 0;
    }

    /** The date's own number, of a start or of an end. */
    private static function base(int $var): int
    {
        return $var < 0 ? -$var - 1 : $var;
    }

    public static function kind(int $var): int
    {
        return self::base($var) % 4;
    }

    public static function id(int $var): int
    {
        return intdiv(self::base($var), 4);
    }

    public static function field(int $var): string
    {
        return self::FIELDS[self::base($var) % 4];
    }

    public static function type(int $var): string
    {
        return self::TYPES[self::base($var) % 4];
    }
}
