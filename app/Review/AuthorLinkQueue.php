<?php

namespace App\Review;

use App\Models\FieldSuggestion;
use App\Models\Person;
use App\Models\PersonReview;
use App\Models\Work;
use App\Support\Fa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A work whose author is a name only, and the one person of the site it may be (step 14.17): PersonReview rows of type
 * author_link, made by fahares:queue-author-links. «آری» is the ordinary suggestion that the work's author is that
 * person (author_is, applied as contributor_set); «نه» is author_not, which keeps the name as it is. The reviewer
 * decides in the panel's «پیوند پدیدآور» (AuthorLinkController), which settles the opinions.
 */
class AuthorLinkQueue extends PersonQueue
{
    public const ANSWERS = [
        'yes' => 'آری، همین شخص است',
        'no' => 'نه، شخص دیگری است',
    ];

    public function key(): string
    {
        return 'author';
    }

    protected function type(): string
    {
        return 'author_link';
    }

    public function title(): string
    {
        return 'پدیدآور اثر';
    }

    public function blurb(): string
    {
        return 'فهرست فقط نام پدیدآور را آورده است. شخصی با همین نام در سایت هست؛ بگویید همان است یا نه.';
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('person_reviews.id');
    }

    public function card(Model $item): array
    {
        $work = $this->work($item);
        $person = Person::find($item->person_ids[0] ?? 0);
        $years = $work ? DB::table('manuscripts')->where('work_id', $work->id)->whereNotNull('copy_date_from')->pluck('copy_date_from') : collect();

        return [
            'work' => $work,
            'profile' => $person ? (app(\App\Services\PersonReviewQueue::class)->profiles([$person->id], false)[$person->id] ?? null) : null,
            'person' => $person,
            'translit' => $item->suggestion['translit'] ?? null,
            'earliest' => $years->min(),
            'copies' => $work ? DB::table('manuscripts')->where('work_id', $work->id)->count() : 0,
        ];
    }

    public function answers(): array
    {
        return self::ANSWERS;
    }

    public function suggestion(Model $item, string $answer, array $input): array
    {
        $person = Person::find($item->person_ids[0] ?? 0)
            ?? throw ValidationException::withMessages(['answer' => 'این شخص دیگر وجود ندارد.']);

        return match ($answer) {
            'yes' => [
                'kind' => 'relation',
                'field_name' => 'author_is',
                'current_value' => null,
                'suggested_value' => 'پدیدآور این اثر ' . $person->name . ' (#' . Fa::digits((string) $person->id) . ') است.',
                'payload' => ['target_id' => $person->id],
                'review_answer' => 'yes',
            ],
            'no' => [
                'kind' => 'relation',
                'field_name' => 'author_not',
                'current_value' => null,
                'suggested_value' => 'پدیدآور این اثر ' . $person->name . ' (#' . Fa::digits((string) $person->id) . ') نیست.',
                'payload' => ['person_id' => $person->id],
                'review_answer' => 'no',
            ],
            default => throw ValidationException::withMessages(['answer' => 'یکی از پاسخ‌ها را برگزینید.']),
        };
    }

    public function describe(FieldSuggestion $opinion): string
    {
        return $opinion->review_answer === 'yes' ? 'همین شخص است' : 'شخص دیگری است';
    }

    public function record(Model $item): Model
    {
        return $this->work($item) ?? throw ValidationException::withMessages(['answer' => 'این اثر دیگر وجود ندارد.']);
    }

    public function reviewUrl(int $itemId): ?string
    {
        return route('admin.author-links.index') . '#author-link-' . $itemId;
    }

    /** The decision of the panel's queue in the answers' form. */
    public static function decision(PersonReview $review): ?string
    {
        return match ($review->status) {
            'linked' => 'yes',
            'not_linked' => 'no',
            default => null,
        };
    }

    private function work(Model $item): ?Work
    {
        return Work::find($item->suggestion['work_id'] ?? 0);
    }
}
