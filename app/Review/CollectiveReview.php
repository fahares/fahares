<?php

namespace App\Review;

use App\Models\FieldSuggestion;
use App\Models\User;
use App\Notifications\SuggestionNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «تصحیح جمعی» (backlog item 80): the panel's review queues answered by signed-in researchers. Each answer is a pending
 * suggestion in field_suggestions, tied to its queue item by review_queue and review_item_id; one per user and item,
 * replaced when the user answers again. A verified scholar's opinion weighs more. Agreeing opinions move an item up the
 * reviewers' desk, and an item with enough agreeing weight leaves the public queue. A reviewer's decision on the item
 * settles every pending opinion on it: the agreeing ones are approved (and credited), the others rejected.
 */
class CollectiveReview
{
    /** The weight of a verified scholar's opinion; anyone else's is 1. */
    public const SCHOLAR_WEIGHT = 2;

    /** Agreeing weight at which an item leaves the public queue and waits only for the reviewer. */
    public const ENOUGH = 3;

    /** @return array<string, class-string<ReviewQueue>> */
    public const QUEUES = [
        'catalog' => CatalogMatchQueue::class,
        'namesake' => NamesakeQueue::class,
        'mixed' => MixedRecordQueue::class,
    ];

    /** Queues whose items are decided only in the panel's queue, never by approving an answer on the desk. */
    public const DECIDED_IN_QUEUE = ['namesake', 'mixed'];

    public static function title(): string
    {
        return config('contributions.review_title');
    }

    /** @return Collection<string, ReviewQueue> */
    public static function queues(): Collection
    {
        return collect(self::QUEUES)->map(fn ($class) => app($class));
    }

    public static function queue(?string $key): ?ReviewQueue
    {
        return isset(self::QUEUES[$key]) ? app(self::QUEUES[$key]) : null;
    }

    public static function weightSql(string $users = 'u'): string
    {
        return "CASE WHEN {$users}.is_verified_scholar = 1 THEN " . self::SCHOLAR_WEIGHT . ' ELSE 1 END';
    }

    public static function weight(?User $user): int
    {
        return $user?->is_verified_scholar ? self::SCHOLAR_WEIGHT : 1;
    }

    /** Pending opinions of one queue, with the weight of each: the base of the counts below. */
    private static function pendingOpinions(?string $queue = null): QueryBuilder
    {
        return DB::table('field_suggestions as fs')
            ->leftJoin('users as u', 'u.id', '=', 'fs.user_id')
            ->whereNotNull('fs.review_queue')->where('fs.status', 'pending')
            ->when($queue, fn ($q) => $q->where('fs.review_queue', $queue));
    }

    /**
     * Per item, the weight of its best-supported answer: review_queue, review_item_id, agreement. The desk orders by it
     * and the public queue hides items that reached ENOUGH.
     */
    public static function agreement(?string $queue = null): QueryBuilder
    {
        $answers = self::pendingOpinions($queue)
            ->groupBy('fs.review_queue', 'fs.review_item_id', 'fs.review_answer')
            ->selectRaw('fs.review_queue, fs.review_item_id, SUM(' . self::weightSql() . ') AS weight');

        return DB::query()->fromSub($answers, 'a')
            ->groupBy('a.review_queue', 'a.review_item_id')
            ->selectRaw('a.review_queue, a.review_item_id, MAX(a.weight) AS agreement');
    }

    /** Ids of the queue's items that have enough agreeing weight. */
    public static function settledEnough(string $queue): QueryBuilder
    {
        return DB::query()->fromSub(self::agreement($queue), 'g')->where('g.agreement', '>=', self::ENOUGH)->select('g.review_item_id');
    }

    /** The items still waiting for researchers: open, without enough agreeing opinions. */
    public static function waiting(ReviewQueue $queue, array $filters = []): Builder
    {
        $query = $queue->filter($queue->open(), $filters);
        $key = $query->getModel()->getQualifiedKeyName();

        return $query->whereNotIn($key, self::settledEnough($queue->key()));
    }

    /**
     * The next item for this researcher: waiting, not answered or skipped by them; items others have begun come first,
     * so they reach a verdict sooner.
     */
    public static function next(ReviewQueue $queue, ?User $user, array $filters = [], array $skipped = [])
    {
        $query = self::waiting($queue, $filters);
        $model = $query->getModel();
        $key = $model->getQualifiedKeyName();
        if ($user) {
            $query->whereNotIn($key, FieldSuggestion::where('review_queue', $queue->key())->where('user_id', $user->id)->select('review_item_id'));
        }
        if ($skipped) {
            $query->whereNotIn($key, array_map('intval', $skipped));
        }
        $begun = self::pendingOpinions($queue->key())->select('fs.review_item_id');
        $query->orderByRaw("{$key} IN ({$begun->toSql()}) DESC", $begun->getBindings());

        return $queue->order($query)->first();
    }

    /** The researcher's standing opinion on an item, if any. */
    public static function opinionOf(User $user, string $queue, int $itemId): ?FieldSuggestion
    {
        return FieldSuggestion::where('review_queue', $queue)->where('review_item_id', $itemId)->where('user_id', $user->id)
            ->latest('id')->first();
    }

    /**
     * Files the answer as a pending suggestion, or replaces the user's earlier pending one on the same item. A reviewed
     * opinion is not replaced: the item is decided and no longer open.
     */
    public static function answer(ReviewQueue $queue, $item, User $user, string $answer, array $input): FieldSuggestion
    {
        $columns = $queue->suggestion($item, $answer, $input);
        $record = $queue->record($item);
        $credit = trim((string) ($input['credit_name'] ?? '')) ?: null;

        return DB::transaction(function () use ($queue, $item, $user, $columns, $record, $credit, $input) {
            $existing = FieldSuggestion::where('review_queue', $queue->key())->where('review_item_id', $item->getKey())
                ->where('user_id', $user->id)->where('status', 'pending')->lockForUpdate()->first();
            $first = ! $existing && ! FieldSuggestion::where('review_queue', $queue->key())->where('review_item_id', $item->getKey())->where('status', 'pending')->exists();

            $values = $columns + [
                'suggestable_type' => $record::class,
                'suggestable_id' => $record->getKey(),
                'review_queue' => $queue->key(),
                'review_item_id' => $item->getKey(),
                'rationale_citation' => trim((string) ($input['note'] ?? '')) ?: null,
                'user_id' => $user->id,
                'guest_name' => $user->name,
                'guest_email' => $user->email,
                'notify_submitter' => false,
                'status' => 'pending',
            // The name is published once the email is verified (backlog item 76)
            ] + \App\Auth\HeldCredit::columns($user, $credit);
            $opinion = $existing ? tap($existing)->update($values) : FieldSuggestion::create($values);

            // The reviewers hear of an item's first opinion; the later ones only reorder the desk
            if ($first) {
                DB::afterCommit(fn () => SuggestionNotifier::submitted($opinion));
            }

            return $opinion;
        });
    }

    /**
     * A reviewer decided the item: the pending opinions that agree with the decision are approved, the others rejected,
     * and each researcher is told. $answer is the decision in the queue's comparable form (review_answer).
     *
     * @return array{approved: int, rejected: int}
     */
    public static function settle(string $queue, int $itemId, string $answer, ?User $reviewer, ?int $exceptId = null): array
    {
        $opinions = FieldSuggestion::where('review_queue', $queue)->where('review_item_id', $itemId)->where('status', 'pending')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->get();
        $counts = ['approved' => 0, 'rejected' => 0];
        foreach ($opinions as $opinion) {
            $agrees = $opinion->review_answer === $answer;
            $opinion->update([
                'status' => $agrees ? 'approved' : 'rejected',
                'resolution' => ['applied' => $agrees, 'collective' => true],
                'admin_notes' => $agrees ? 'هم‌سو با تصمیم داور (تصحیح جمعی).' : 'داور تصمیم دیگری گرفت (تصحیح جمعی).',
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
            ]);
            $counts[$agrees ? 'approved' : 'rejected']++;
            DB::afterCommit(fn () => SuggestionNotifier::reviewed($opinion->fresh()));
        }

        return $counts;
    }

    /**
     * The opinions on the items of these suggestions, side by side for the desk: per item, each answer with its
     * opinions and weighted count, best supported first.
     *
     * @param  iterable<FieldSuggestion>  $suggestions
     * @return array<string, array{total: int, weight: int, answers: list<array{answer: string, label: string, weight: int, opinions: Collection}>}>
     */
    public static function opinionsFor(iterable $suggestions, bool $withOpinions = false): array
    {
        return self::opinionsOn(collect($suggestions)->filter(fn ($s) => $s->review_queue)->map(fn ($s) => [$s->review_queue, (int) $s->review_item_id])->all(), $withOpinions);
    }

    /**
     * The same, for queue items: [[queue, id], …]. Keyed «queue:id».
     */
    public static function opinionsOn(array $items, bool $withOpinions = false): array
    {
        $items = collect($items)->unique(fn ($k) => implode(':', $k));
        if ($items->isEmpty()) {
            return [];
        }

        $opinions = FieldSuggestion::with('user')->where('status', 'pending')
            ->where(function ($q) use ($items) {
                foreach ($items as [$queue, $id]) {
                    $q->orWhere(fn ($w) => $w->where('review_queue', $queue)->where('review_item_id', $id));
                }
            })->orderBy('id')->get();

        $out = [];
        foreach ($opinions->groupBy(fn ($o) => "{$o->review_queue}:{$o->review_item_id}") as $itemKey => $group) {
            $queue = self::queue($group->first()->review_queue);
            $answers = $group->groupBy('review_answer')->map(fn ($same, $answer) => [
                'answer' => (string) $answer,
                'label' => $queue ? $queue->describe($same->first()) : (string) $answer,
                'weight' => $same->sum(fn ($o) => self::weight($o->user)),
                'count' => $same->count(),
                'opinions' => $withOpinions ? $same->values() : collect(),
            ])->sortByDesc('weight')->values()->all();
            $out[$itemKey] = [
                'total' => $group->count(),
                'weight' => $answers[0]['weight'] ?? 0,
                'answers' => $answers,
            ];
        }

        return $out;
    }

    /** A researcher's answers in «تصحیح جمعی», for their page: given and agreed with by a reviewer. */
    public static function contributionsOf(User $user): array
    {
        $mine = FieldSuggestion::where('user_id', $user->id)->whereNotNull('review_queue');

        return ['answers' => (clone $mine)->count(), 'approved' => (clone $mine)->where('status', 'approved')->count()];
    }

    /** Per queue: open items, and how many still wait for researchers. */
    public static function counts(): array
    {
        return self::queues()->map(fn (ReviewQueue $q) => [
            'open' => $q->open()->count(),
            'waiting' => self::waiting($q)->count(),
        ])->all();
    }
}
