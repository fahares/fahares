<?php

namespace App\Review;

use App\Models\FieldSuggestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One review queue of the panel as the public answers it in «تصحیح جمعی» (backlog item 80). The queue says which of its
 * items are still undecided, how one item is shown on a card, which answers it takes, and what ordinary suggestion an
 * answer files; CollectiveReview counts the opinions and picks the next item. An answer never changes the data: only a
 * reviewer's decision does, and settle() then closes the opinions on that item.
 */
abstract class ReviewQueue
{
    /** The queue's key in addresses and in field_suggestions.review_queue: «catalog», never the section's title. */
    abstract public function key(): string;

    /** The queue's name and one sentence on what is asked, in plain words. */
    abstract public function title(): string;

    abstract public function blurb(): string;

    /** The undecided items: those a reviewer has not settled. */
    abstract public function open(): Builder;

    /**
     * The narrowing a researcher may pick: name => ['label' => …, 'options' => [value => label]].
     *
     * @return array<string, array{label: string, options: array}>
     */
    abstract public function filterOptions(): array;

    abstract public function filter(Builder $query, array $filters): Builder;

    /** The order of the next items: those already started first is CollectiveReview's, this is the rest. */
    abstract public function order(Builder $query): Builder;

    /** The data of an item's card for the queue's view (contribute.cards.{key}). */
    abstract public function card(Model $item): array;

    /** @return array<string, string> answer => label of the button; «نمی‌دانم» is everyone's and files nothing */
    abstract public function answers(): array;

    /**
     * Checks one answer and returns the suggestion's own columns: kind, field_name, current_value, suggested_value,
     * payload and review_answer (the comparable form of the answer, for counting opinions that agree).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    abstract public function suggestion(Model $item, string $answer, array $input): array;

    /** The opinion as one line on the reviewers' desk and in «اصلاحات و تعلیقات»: «این نسخه در ج ۲، ص ۷ آمده است». */
    abstract public function describe(FieldSuggestion $opinion): string;

    /** The record the suggestion is filed on. */
    abstract public function record(Model $item): Model;

    /** The panel page where a reviewer decides the item. */
    abstract public function reviewUrl(int $itemId): ?string;

    public function find(int $id): ?Model
    {
        return $this->open()->whereKey($id)->first();
    }

    /** Filters cleaned to the queue's own options. */
    public function filters(array $input): array
    {
        $out = [];
        foreach ($this->filterOptions() as $name => $filter) {
            $value = $input[$name] ?? null;
            if ($value !== null && $value !== '' && array_key_exists($value, $filter['options'])) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }
}
