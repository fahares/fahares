<?php

namespace App\Models;

use App\Support\CopyPlaces;
use App\Support\DateSpan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period of a person's life: what they did, where, and from when to when (backlog item 139, step 14.218).
 * The date columns are those of a DateSpan under the prefix `period`.
 */
class PersonPeriod extends Model
{
    protected $guarded = ['id'];

    /** kind => label */
    public const KINDS = [
        'residence' => 'اقامت',
        'study' => 'تحصیل',
        'teaching' => 'تدریس',
        'travel' => 'سفر',
        'office' => 'منصب',
    ];

    /** How the date was entered: a duration, one event at an unknown time, or one year. */
    public const MODES = ['span' => 'از … تا … (طول مدت)', 'between' => 'زمانی میان … و …', 'point' => 'در سال …'];

    public const CERTAINTIES = ['probable' => 'محتمل', 'doubtful' => 'مشکوک'];

    protected static function booted(): void
    {
        // The city and the country or region the place names, beside its text, as for a copy's place (step 14.209)
        static::saving(function (PersonPeriod $period) {
            if ($period->isDirty('place') || ! $period->exists) {
                $period->city = CopyPlaces::cityOf($period->place);
                $period->region = CopyPlaces::regionOf($period->place);
            }
        });
    }

    protected function casts(): array
    {
        return ['period_from' => 'integer', 'period_to' => 'integer', 'period_start_to' => 'integer', 'period_end_from' => 'integer'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function date(): DateSpan
    {
        return DateSpan::fromColumns($this, 'period');
    }

    /** The date in words with its «ق»: «از 1000 تا 1030 ق», «از 1252 ق به بعد». */
    public function getDateTextAttribute(): ?string
    {
        $text = $this->date()->text();

        return $text === null ? null : (str_ends_with($text, ' به بعد') ? mb_substr($text, 0, -7) . ' ق به بعد' : $text . ' ق');
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** The city, else the country or region, else the place as written; null when the period names no place. */
    public function getPlaceNameAttribute(): ?string
    {
        return $this->city ?: ($this->region ?: ($this->place ?: null));
    }

    /**
     * The date columns of a form's years: `mode` and the four years `start`, `start_to`, `end`, `end_to`.
     *   - point: the year `start`;
     *   - between: some time from `start` to `end`;
     *   - span: from `start` (or, with `start_to`, from some time between the two) to `end` (or, with `end_to`, to some
     *     time between `end` and `end_to`); a missing start or end leaves that side open.
     *
     * @param  array{mode?: ?string, start?: ?int, start_to?: ?int, end?: ?int, end_to?: ?int}  $input
     * @return array<string, int|string|null> the columns, or ['error' => message]
     */
    public static function dateColumns(array $input, ?string $certainty = null): array
    {
        $year = fn (string $key) => ($input[$key] ?? null) === null || $input[$key] === '' ? null : (int) $input[$key];
        [$start, $startTo, $end, $endTo] = [$year('start'), $year('start_to'), $year('end'), $year('end_to')];
        $mode = $input['mode'] ?? 'span';
        if ($mode === 'point') {
            $span = new DateSpan($start, $start, null, null, null, $certainty);
        } elseif ($mode === 'between') {
            $span = new DateSpan($start, $end, $start !== $end ? DateSpan::BETWEEN : null, null, null, $certainty);
        } else {
            // the outer years are the earliest start and the latest end
            $to = $endTo ?? $end;
            $span = new DateSpan($start, $to, $start !== $to ? DateSpan::SPAN : null,
                $startTo !== null && $startTo !== $start ? $startTo : null, $endTo !== null && $end !== $endTo ? $end : null, $certainty);
        }
        if ($span->isEmpty()) {
            return ['error' => 'دست‌کم یک سال لازم است.'];
        }
        $order = array_values(array_filter([$span->from, $span->startTo, $span->endFrom, $span->to], fn ($y) => $y !== null));
        $sorted = $order;
        sort($sorted);
        if ($order !== $sorted || ($mode === 'point' && $start === null) || ($mode === 'between' && ($start === null || $end === null))) {
            return ['error' => 'سال‌ها باید به ترتیب باشند: آغاز پیش از پایان.'];
        }

        return $span->columns('period');
    }

    /**
     * The form's fields of the stored date, the reverse of dateColumns.
     *
     * @return array{mode: string, start: ?int, start_to: ?int, end: ?int, end_to: ?int}
     */
    public function dateInput(): array
    {
        $date = $this->date();
        if (! $date->isRange()) {
            return ['mode' => 'point', 'start' => $date->from, 'start_to' => null, 'end' => null, 'end_to' => null];
        }
        if ($date->kind === DateSpan::BETWEEN) {
            return ['mode' => 'between', 'start' => $date->from, 'start_to' => null, 'end' => $date->to, 'end_to' => null];
        }

        return ['mode' => 'span', 'start' => $date->from, 'start_to' => $date->startTo,
            'end' => $date->endFrom ?? $date->to, 'end_to' => $date->endFrom !== null ? $date->to : null];
    }
}
