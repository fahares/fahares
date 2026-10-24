<?php

namespace App\Review;

use App\Models\Person;
use App\Models\PersonReview;
use App\Services\PersonReviewQueue;
use App\Support\Fa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The two queues of the panel's «بازبینی اشخاص» (backlog item 78) as «تصحیح جمعی» asks them: their items are
 * PersonReview rows of one type, still undecided, narrowed by the century of the people in them. The reviewer decides
 * in the panel's queue, where PersonReviewController settles the opinions.
 */
abstract class PersonQueue extends ReviewQueue
{
    /** PersonReview::type of the queue's items. */
    abstract protected function type(): string;

    public function open(): Builder
    {
        return PersonReview::query()->where('person_reviews.type', $this->type())->whereIn('person_reviews.status', ['open', 'deferred']);
    }

    public function filterOptions(): array
    {
        return Cache::remember("collective-review.{$this->key()}.filters", 600, function () {
            $groups = $this->open()->pluck('person_ids');
            $century = Person::whereIn('id', $groups->flatten()->unique()->values())->whereNotNull('century_hijri')->pluck('century_hijri', 'id');
            $centuries = [];
            foreach ($groups as $ids) {
                foreach (collect((array) $ids)->map(fn ($id) => $century[$id] ?? null)->filter()->unique() as $c) {
                    $centuries[(int) $c] = ($centuries[(int) $c] ?? 0) + 1;
                }
            }
            ksort($centuries);

            return ['century' => ['label' => 'سده', 'options' => collect($centuries)
                ->mapWithKeys(fn ($n, $c) => [$c => 'سده‌ی ' . Fa::digits((string) $c) . ' (' . Fa::num($n) . ')'])->all()]];
        });
    }

    public function filter(Builder $query, array $filters): Builder
    {
        if (! ($century = $filters['century'] ?? null)) {
            return $query;
        }

        return $query->whereExists(fn ($q) => $q->from('people')->where('people.century_hijri', (int) $century)
            ->whereRaw('JSON_CONTAINS(person_reviews.person_ids, CAST(people.id AS JSON))'));
    }

    /** The groups the finder is surest of first, then in the panel's order. */
    public function order(Builder $query): Builder
    {
        return $query->orderByRaw("person_reviews.tier = 'likely' DESC")->orderBy('person_reviews.id');
    }

    /** The people of the item, with what a researcher needs to judge them, in the item's order. */
    protected function profiles(PersonReview $item, bool $allCopies): array
    {
        return app(PersonReviewQueue::class)->profiles(array_map('intval', (array) $item->person_ids), $allCopies);
    }

    public function record(Model $item): Model
    {
        return Person::find($item->person_ids[0] ?? 0)
            ?? throw ValidationException::withMessages(['answer' => 'این شخص دیگر وجود ندارد.']);
    }

    public function reviewUrl(int $itemId): ?string
    {
        return route('admin.person-reviews.index', ['type' => $this->type()]) . '#person-review-' . $itemId;
    }

    /** A sorted list of ids from the form, kept to those of the item. */
    protected function chosen(array $input, string $name, array $allowed): array
    {
        $ids = array_values(array_unique(array_intersect(array_map('intval', (array) ($input[$name] ?? [])), array_map('intval', $allowed))));
        sort($ids);

        return $ids;
    }
}
