<?php

namespace App\Review;

use App\Models\FieldSuggestion;
use App\Models\PersonReview;
use App\Support\Fa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Records that may hold several scribes (the panel's «رکوردهای قاطی‌شده»): the copies of one person span more than a
 * lifetime. «برخی از نفر دیگری‌اند» is the ordinary suggestion that a person is conflated (split), with the copies
 * marked in payload.manuscript_ids; «همه از یک نفرند» is one_person, which keeps the data as it is.
 */
class MixedRecordQueue extends PersonQueue
{
    public const ANSWERS = [
        'one' => 'همه را یک نفر نوشته است',
        'split' => 'برخی از نفر دیگری است',
    ];

    /** Copies shown on a card; a record with more is judged on these. */
    public const COPIES = 200;

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

    public function card(Model $item): array
    {
        $profile = collect($this->profiles($item, true))->first();

        return ['profile' => $profile, 'copies' => $profile ? $profile['copies']->take(self::COPIES)->values() : collect()];
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
                'suggested_value' => 'همه‌ی نسخه‌های این رکورد را یک نفر نوشته است.',
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
