<?php

namespace App\Review;

use App\Models\FieldSuggestion;
use App\Models\Person;
use App\Models\PersonReview;
use App\Support\Fa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Namesakes (the panel's «اشخاص هم‌نام»): are these records one person? «یکی‌اند» and «برخی یکی‌اند» are the ordinary
 * suggestion that a person is a duplicate of another (merge_people), with the records to merge in payload.merge_ids;
 * «چند نفرند» is namesakes_apart, which keeps the data as it is.
 */
class NamesakeQueue extends PersonQueue
{
    public const ANSWERS = [
        'same' => 'یک نفرند',
        'some' => 'برخی یک نفرند',
        'distinct' => 'چند نفرند',
    ];

    public function key(): string
    {
        return 'namesake';
    }

    protected function type(): string
    {
        return 'duplicate';
    }

    public function title(): string
    {
        return 'اشخاص هم‌نام';
    }

    public function blurb(): string
    {
        return 'چند رکورد با نامی یکسان یا نزدیک را با تاریخ‌ها و آثار و نسخه‌هایشان ببینید و بگویید یک نفرند یا چند نفر.';
    }

    public function card(Model $item): array
    {
        $people = collect($this->profiles($item, false))->values()->all();

        // «برخی یک نفرند» needs three records or more
        return ['people' => $people, 'reason' => $this->reason($item), 'hide' => count($people) > 2 ? [] : ['some']];
    }

    /** The finder's reason in the public's words; nothing of its internal codes. */
    private function reason(PersonReview $item): ?string
    {
        return $item->tier === 'likely' ? 'نام‌ها و تاریخ‌ها به هم نزدیک‌اند.' : null;
    }

    public function detailed(): array
    {
        return ['some'];
    }

    public function answers(): array
    {
        return self::ANSWERS;
    }

    public function suggestion(Model $item, string $answer, array $input): array
    {
        $ids = array_map('intval', (array) $item->person_ids);
        sort($ids);
        if ($answer === 'some') {
            $chosen = $this->chosen($input, 'people', $ids);
            if (count($chosen) < 2 || count($chosen) === count($ids)) {
                throw ValidationException::withMessages(['people' => count($chosen) === count($ids)
                    ? 'همه را برگزیده‌اید؛ اگر همه یک نفرند «یک نفرند» را بزنید.'
                    : 'دست‌کم دو رکوردی را که یک نفرند برگزینید.']);
            }
            $ids = $chosen;
        }

        return match ($answer) {
            'same', 'some' => [
                'kind' => 'relation',
                'field_name' => 'duplicate',
                'current_value' => null,
                'suggested_value' => $this->mergeText($ids),
                'payload' => ['target_id' => $ids[1], 'merge_ids' => $ids],
                'review_answer' => $answer === 'same' ? 'same' : 'some:' . implode(',', $ids),
            ],
            'distinct' => [
                'kind' => 'relation',
                'field_name' => 'namesakes_apart',
                'current_value' => null,
                'suggested_value' => 'این ' . Fa::num(count($ids)) . ' رکورد هم‌نام چند نفرند.',
                'payload' => ['person_ids' => $ids],
                'review_answer' => 'distinct',
            ],
            default => throw ValidationException::withMessages(['answer' => 'یکی از پاسخ‌ها را برگزینید.']),
        };
    }

    private function mergeText(array $ids): string
    {
        return 'یک نفرند: ' . Person::whereIn('id', $ids)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($p) => $p->name . ' (#' . Fa::digits((string) $p->id) . ')')->implode('، ');
    }

    public function record(Model $item): Model
    {
        $ids = array_map('intval', (array) $item->person_ids);
        sort($ids);

        return Person::find($ids[0] ?? 0) ?? parent::record($item);
    }

    public function describe(FieldSuggestion $opinion): string
    {
        return match (true) {
            $opinion->review_answer === 'same' => 'همه یک نفرند',
            $opinion->review_answer === 'distinct' => 'چند نفرند',
            default => 'برخی یک نفرند: ' . Fa::digits(implode('، ', array_map(fn ($id) => '#' . $id, $opinion->payload['merge_ids'] ?? []))),
        };
    }

    /** The decision of the panel's queue in the answers' form. */
    public static function decision(PersonReview $review): ?string
    {
        $all = array_map('intval', (array) $review->person_ids);
        sort($all);
        $merged = array_map('intval', (array) ($review->decision['merge_ids'] ?? []));
        sort($merged);

        return match ($review->status) {
            'merged' => $merged === $all ? 'same' : 'some:' . implode(',', $merged),
            'distinct' => 'distinct',
            default => null,
        };
    }
}
