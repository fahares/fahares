<?php

namespace App\Support;

use App\Models\ManuscriptCollection;

/**
 * What a «مجموعه» is said to be, worked out from what its treatises' own entries say (backlog item 163, step 14.323;
 * docs/plans/collections.md, section 6). The values are derived data: they are worked out again whenever the
 * treatises change, and a value an editor or a library's catalogue gave (basis «editor», «catalogue») is left alone.
 *
 *   two treatises or more state the field, all the same     → that value                       (agreement)
 *   they differ, but each is a piece of the fullest one     → the fullest, for binding, paper  (fullest)
 *   one treatise alone states it                            → that value, for the volume's own
 *                                                             fields: binding, size, paper     (single)
 *   they differ                                             → nothing
 *
 * The date and the place of copying are no one's but the treatise's: the collection holds them only when every
 * treatise states the same, to show as the volume's shared date. The volumes of one work under one number (kind
 * «set») have a binding and a size each, so nothing is derived for them.
 */
class CollectionValues
{
    public const AGREEMENT = 'agreement';

    public const FULLEST = 'fullest';

    public const SINGLE = 'single';

    /** A value these give is not derived again */
    public const GIVEN = ['editor', 'catalogue'];

    /** The volume's own fields: shown and edited on the collection */
    public const OWN = ['binding', 'dimensions', 'paper'];

    /** What a treatise reads from its collection when its own entry is silent */
    public const INHERITED = ['script_names', 'lines', 'scribe'];

    /** Shown as the volume's shared value when every treatise states it; never read by a silent treatise */
    public const SHARED = ['copy_date_raw', 'copy_place'];

    /** Fields in which a shorter wording inside a fuller one is the same thing said in brief */
    private const WORDED = ['binding', 'paper'];

    /** The columns of a copy the rule reads */
    public const COPY_COLUMNS = ['id', 'collection_id', 'binding', 'dimensions', 'paper', 'script_names', 'script_style', 'lines', 'lines_raw',
        'scribe_id', 'scribe_name', 'copy_date_raw', 'copy_place'];

    /** The value columns of a collection the rule writes, by field */
    private const COLUMNS = ['scribe' => ['scribe_id', 'scribe_name'], 'script_names' => ['script_names', 'script_style'], 'lines' => ['lines', 'lines_raw']];

    /** @return list<string> */
    public static function fields(): array
    {
        return [...self::OWN, ...self::INHERITED, ...self::SHARED];
    }

    /** @return list<string> the collection's columns a field fills */
    public static function columnsOf(string $field): array
    {
        return self::COLUMNS[$field] ?? [$field];
    }

    /**
     * The values of a collection of these copies, and where each comes from.
     *
     * @param  iterable<object>  $copies  rows with COPY_COLUMNS
     * @return array{values: array<string, mixed>, basis: array<string, string>} every value column, null where nothing is derived
     */
    public static function derive(iterable $copies, string $kind = ManuscriptCollection::BOUND): array
    {
        $copies = is_array($copies) ? $copies : iterator_to_array($copies, false);
        $values = [];
        $basis = [];
        foreach (self::fields() as $field) {
            foreach (self::columnsOf($field) as $column) {
                $values[$column] = null;
            }
            if ($kind !== ManuscriptCollection::BOUND || count($copies) < 2) {
                continue;
            }
            // what each treatise states, by its compared form
            $stated = [];
            foreach ($copies as $copy) {
                $key = self::compared($field, $copy);
                if ($key !== null) {
                    $stated[$key][] = $copy;
                }
            }
            $count = array_sum(array_map('count', $stated));
            $from = null;
            if (count($stated) === 1 && $count >= 2 && (! in_array($field, self::SHARED, true) || $count === count($copies))) {
                $from = self::AGREEMENT;
                $source = self::commonest(reset($stated), $field);
            } elseif (count($stated) === 1 && in_array($field, self::OWN, true)) {
                $from = self::SINGLE;
                $source = reset($stated)[0];
            } elseif (count($stated) > 1 && in_array($field, self::WORDED, true)) {
                $keys = array_map('strval', array_keys($stated));
                usort($keys, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                if (! array_filter($keys, fn ($key) => ! str_contains($keys[0], $key))) {
                    $from = self::FULLEST;
                    $source = self::commonest($stated[$keys[0]], $field);
                }
            }
            if ($from === null) {
                continue;
            }
            $basis[$field] = $from;
            foreach (self::columnsOf($field) as $column) {
                $values[$column] = $source->{$column} ?? null;
            }
            // the style of the hand is the volume's only when every treatise that names the hand gives the same
            if ($field === 'script_names' && count(array_unique(array_map(fn ($c) => self::folded($c->script_style ?? null) ?? '', reset($stated)))) > 1) {
                $values['script_style'] = null;
            }
        }

        return ['values' => $values, 'basis' => $basis];
    }

    /** What a copy states for a field, in the form two statements are compared in; null when it states nothing. */
    public static function compared(string $field, object $copy): ?string
    {
        return match ($field) {
            'scribe' => ($copy->scribe_id ?? null) ? '#' . (int) $copy->scribe_id : self::folded($copy->scribe_name ?? null),
            'lines' => ($copy->lines ?? null) ? (string) (int) $copy->lines : null,
            'dimensions' => ($size = self::folded($copy->dimensions ?? null)) === null ? null : (str_replace(['سم', 'x', 'X', '*'], ['', '×', '×', '×'], $size) ?: null),
            default => self::folded($copy->{$field} ?? null),
        };
    }

    /** A text with its digits, letters and spacing made one: «تیماج قهوه ای» and «تیماج قهوه‌ای», «شوال1125ق» and «شوال ۱۱۲۵ق». */
    public static function folded(mixed $text): ?string
    {
        $text = strtr((string) $text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', 'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']);
        $text = preg_replace('/[\s\x{200C}\x{200D}\x{0640}،,.؛;]+/u', '', $text);

        return $text === '' || $text === null ? null : $text;
    }

    /** Of copies that state one thing, the one whose wording most of them use (the first of them in the order given). */
    private static function commonest(array $copies, string $field): object
    {
        $column = self::columnsOf($field)[0] === 'scribe_id' ? 'scribe_name' : self::columnsOf($field)[0];
        $forms = array_count_values(array_map(fn ($c) => (string) ($c->{$column} ?? ''), $copies));
        arsort($forms);
        $form = (string) array_key_first($forms);
        foreach ($copies as $copy) {
            if ((string) ($copy->{$column} ?? '') === $form) {
                return $copy;
            }
        }

        return $copies[0];
    }
}
