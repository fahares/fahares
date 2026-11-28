<?php

namespace App\Review;

use App\Models\FieldSuggestion;
use App\Models\PersonReview;
use App\Services\PersonReviewQueue;
use App\Support\Fa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Records that may hold several scribes (the panel's «رکوردهای قاطی‌شده»): the copies of one person span more than a
 * lifetime. «برخی از نفر دیگری‌اند» is the ordinary suggestion that a person is conflated (split), with the copies
 * marked in payload.manuscript_ids; «همه از یک نفرند» is one_person, which keeps the data as it is.
 *
 * The widest gap comes first (step 14.430, backlog item 125): the surest cases. A card marks beforehand the copies a
 * machine would split off (suggestedSplit); the researcher keeps, mends or clears the marks, and nothing is split
 * before a reviewer decides in the panel.
 */
class MixedRecordQueue extends PersonQueue
{
    public const ANSWERS = [
        'one' => 'همه را یک نفر نوشته است',
        'split' => 'برخی از نفر دیگری است',
    ];

    /** Copies shown on a card; a record with more is judged on these. */
    public const COPIES = 200;

    /** The scribed_gap of a mixed item's one person, as SQL over a row of person_reviews. */
    public const GAP_SQL = "(SELECT people.scribed_gap FROM people WHERE people.id = CAST(JSON_EXTRACT(person_reviews.person_ids, '$[0]') AS UNSIGNED))";

    public function key(): string
    {
        return 'mixed';
    }

    protected function type(): string
    {
        return 'mixed';
    }

    public function title(): string
    {
        return 'یک نام، چند کاتب؟';
    }

    public function blurb(): string
    {
        return 'نسخه‌هایی که به نام یک کاتب ثبت شده‌اند ولی تاریخشان بیش از یک عمر از هم دور است. بگویید کدام را کس دیگری نوشته است.';
    }

    /** The widest sure gap between the copies first (people.scribed_gap), then in the panel's order. */
    public function order(Builder $query): Builder
    {
        return parent::order($query->orderByRaw(self::GAP_SQL . ' DESC'));
    }

    public function card(Model $item): array
    {
        $profile = collect($this->profiles($item, true))->first();
        $copies = $profile ? $profile['copies']->take(self::COPIES)->values() : collect();

        return ['profile' => $profile, 'copies' => $copies, 'suggested' => self::suggestedSplit($copies)];
    }

    /**
     * The copies a machine would give to another scribe: the copies are cut where the widest sure gap between them
     * lies, the start of the later ones less the latest end of the earlier, and the smaller side is named (the
     * later one when both are as large). Nothing when no gap is over PersonReviewQueue::MIXED_SPAN. A copy without
     * both bounds of its date is on neither side.
     *
     * @param  Collection<int, object>  $copies  rows of PersonReviewQueue::profiles
     * @return list<int>
     */
    public static function suggestedSplit(Collection $copies): array
    {
        $bound = fn ($year) => $year !== null && $year >= 1 && $year <= 1500 ? (int) $year : null;
        $dated = $copies->map(fn ($c) => ['id' => (int) $c->id, 'from' => $bound($c->date_from ?? null), 'to' => $bound($c->date_to ?? null)])
            ->filter(fn ($c) => $c['from'] !== null && $c['to'] !== null)->sortBy([['from', 'asc'], ['to', 'asc'], ['id', 'asc']])->values();
        [$cut, $widest, $latestEnd] = [null, PersonReviewQueue::MIXED_SPAN, null];
        foreach ($dated as $i => $c) {
            if ($latestEnd !== null && $c['from'] - $latestEnd > $widest) {
                [$cut, $widest] = [$i, $c['from'] - $latestEnd];
            }
            $latestEnd = max($latestEnd ?? $c['to'], $c['to']);
        }
        if ($cut === null) {
            return [];
        }
        $side = $cut < $dated->count() - $cut ? $dated->take($cut) : $dated->slice($cut);

        return $side->pluck('id')->values()->all();
    }

    public function detailed(): array
    {
        return ['split'];
    }

    public function answers(): array
    {
        return self::ANSWERS;
    }

    public function suggestion(Model $item, string $answer, array $input): array
    {
        if ($answer === 'one') {
            return [
                'kind' => 'relation',
                'field_name' => 'one_person',
                'current_value' => null,
                'suggested_value' => 'همۀ نسخه‌های این رکورد را یک نفر نوشته است.',
                'payload' => null,
                'review_answer' => 'one',
            ];
        }
        if ($answer !== 'split') {
            throw ValidationException::withMessages(['answer' => 'یکی از پاسخ‌ها را برگزینید.']);
        }

        $copies = collect($this->card($item)['copies'])->pluck('id')->all();
        $chosen = $this->chosen($input, 'copies', $copies);
        if (! $chosen || count($chosen) === count($copies)) {
            throw ValidationException::withMessages(['copies' => $chosen
                ? 'همه را برگزیده‌اید؛ نسخه‌هایی را بزنید که از نفر دیگری است.'
                : 'نسخه‌هایی را که از نفر دیگری است علامت بزنید.']);
        }
        $name = trim((string) ($input['name'] ?? ''));

        return [
            'kind' => 'relation',
            'field_name' => 'conflated',
            'current_value' => null,
            'suggested_value' => Fa::num(count($chosen)) . ' نسخه از کاتب دیگری است' . ($name !== '' ? ': ' . $name : '') . '.',
            'payload' => array_filter(['manuscript_ids' => $chosen, 'name' => $name ?: null]),
            'review_answer' => 'split',
        ];
    }

    public function describe(FieldSuggestion $opinion): string
    {
        return $opinion->review_answer === 'one'
            ? 'همه را یک نفر نوشته است'
            : Fa::num(count($opinion->payload['manuscript_ids'] ?? [])) . ' نسخه از کاتب دیگری است' . (! empty($opinion->payload['name']) ? ' (' . $opinion->payload['name'] . ')' : '');
    }

    /** The decision of the panel's queue in the answers' form. */
    public static function decision(PersonReview $review): ?string
    {
        return match ($review->status) {
            'split' => 'split',
            'dismissed' => 'one',
            default => null,
        };
    }
}
