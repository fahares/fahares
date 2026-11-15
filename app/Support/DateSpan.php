<?php

namespace App\Support;

/**
 * One date as Fahares stores it (item 134, step 14.188): two ends, each a range of Hijri years, and what the range
 * between them means. Every date column set of every table has this shape, under its own prefix (`copy_date`,
 * `composition_date`, `death`, and whatever comes later: a stay, an office, an endowment).
 *
 *   from      the earliest year the start may be        (`<prefix>_from`)
 *   startTo   the latest year the start may be          (`<prefix>_start_to`)
 *   endFrom   the earliest year the end may be          (`<prefix>_end_from`)
 *   to        the latest year the end may be            (`<prefix>_to`)
 *   kind      SPAN: it lasted from the start to the end («از 1052 تا 1057»);
 *             BETWEEN: one event at some time between from and to («قرن 13», «1250 یا 1270»);
 *             null: not a range, or a range whose meaning the text does not settle
 *
 * `from` and `to` are the outer cover of the date whatever its kind, so a reader that knows only those two reads
 * every date rightly as «somewhere in here». `startTo` and `endFrom` matter only for a SPAN whose ends are themselves
 * uncertain («از میان 1000 و 1010 تا میان 1020 و 1030»); null means that end is exact (startTo = from, endFrom = to).
 * An open end is a null `from` or `to`.
 */
final class DateSpan
{
    public const SPAN = 'span';

    public const BETWEEN = 'between';

    public const KINDS = [self::SPAN, self::BETWEEN];

    public function __construct(
        public readonly ?int $from,
        public readonly ?int $to,
        public readonly ?string $kind = null,
        public readonly ?int $startTo = null,
        public readonly ?int $endFrom = null,
        public readonly ?string $certainty = null,
        public readonly ?string $hedge = null,
    ) {
    }

    /** The date of a row (a model, a DB row or an array) from its columns under the prefix. */
    public static function fromColumns(object|array $row, string $prefix): self
    {
        $row = is_array($row) ? $row : (method_exists($row, 'getAttributes') ? $row->getAttributes() : (array) $row);
        $int = fn (string $key) => ($row["{$prefix}_{$key}"] ?? null) === null ? null : (int) $row["{$prefix}_{$key}"];

        return new self($int('from'), $int('to'), $row["{$prefix}_kind"] ?? null, $int('start_to'), $int('end_from'),
            $row["{$prefix}_certainty"] ?? null, $row["{$prefix}_hedge_raw"] ?? null);
    }

    /**
     * The date as its columns under the prefix.
     *
     * @return array<string, int|string|null>
     */
    public function columns(string $prefix): array
    {
        return ["{$prefix}_from" => $this->from, "{$prefix}_to" => $this->to, "{$prefix}_certainty" => $this->certainty,
            "{$prefix}_hedge_raw" => $this->hedge === null ? null : mb_substr($this->hedge, 0, 60),
            "{$prefix}_kind" => $this->kind, "{$prefix}_start_to" => $this->startTo, "{$prefix}_end_from" => $this->endFrom];
    }

    public function isEmpty(): bool
    {
        return $this->from === null && $this->to === null;
    }

    /** Whether the date is more than one known year. */
    public function isRange(): bool
    {
        return ! $this->isEmpty() && $this->from !== $this->to;
    }

    public function isSpan(): bool
    {
        return $this->kind === self::SPAN && $this->isRange();
    }

    /**
     * The years the start may lie in: of a span its own range, of anything else the whole date.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function start(): array
    {
        return $this->isSpan() ? [$this->from, $this->from === null ? $this->startTo : ($this->startTo ?? $this->from)] : [$this->from, $this->to];
    }

    /**
     * The years the end may lie in.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function end(): array
    {
        return $this->isSpan() ? [$this->to === null ? $this->endFrom : ($this->endFrom ?? $this->to), $this->to] : [$this->from, $this->to];
    }

    /**
     * The date in words, with Latin digits (the caller sets them in Persian): «1052», «از 1052 تا 1057», «میان 1250 و
     * 1270», «پس از 1100», «از 1000 به بعد», «از میان 1000 و 1010 تا میان 1020 و 1030», and «1084–1107» for a range of
     * no known kind. Null when there is no date.
     */
    public function text(): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }
        if (! $this->isRange()) {
            return (string) $this->from;
        }
        if ($this->kind === self::SPAN) {
            [$startFrom, $startTo] = $this->start();
            [$endFrom, $endTo] = $this->end();
            $end = fn (?int $a, ?int $b) => $a === $b ? (string) $a : "میان {$a} و {$b}";
            if ($this->to === null) {
                return 'از ' . $end($startFrom, $startTo) . ' به بعد';
            }
            if ($this->from === null) {
                return 'تا ' . $end($endFrom, $endTo);
            }

            return 'از ' . $end($startFrom, $startTo) . ' تا ' . $end($endFrom, $endTo);
        }
        if ($this->from === null) {
            return "پیش از {$this->to}";
        }
        if ($this->to === null) {
            return "پس از {$this->from}";
        }

        return $this->kind === self::BETWEEN ? "میان {$this->from} و {$this->to}" : "{$this->from}–{$this->to}";
    }
}
