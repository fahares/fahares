<?php

namespace App\Services;

use App\Models\PersonReview;
use Illuminate\Support\Facades\DB;

/**
 * Builds the admin queue «بازبینی اشخاص» (backlog item 78) from the data:
 *   duplicate — groups DuplicatePeopleFinder puts in the likely or review tier (conflicts are left out);
 *   mixed     — people whose copies span more than a lifetime, likely several namesakes in one record.
 * Decisions stay with the key: a group judged «چند نفرند» is reopened only when a record it did not
 * have joins it; items whose records are gone are closed as «gone».
 */
class PersonReviewQueue
{
    public const MIXED_SPAN = 70;

    /** @return array<string, int> counts of what changed */
    public function refresh(array $seed = []): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'reopened' => 0, 'gone' => 0];
        $seen = ['duplicate' => [], 'mixed' => []];

        foreach (DuplicatePeopleFinder::groups()['groups'] as $group) {
            if ($group['tier'] === 'conflict') {
                continue;
            }
            $ids = array_map('intval', array_column($group['members'], 'id'));
            sort($ids);
            $seen['duplicate'][] = $group['key'];
            $this->upsert('duplicate', $group['key'], $ids, $group['tier'], $group['reason'], $seed[$group['key']] ?? null, $stats,
                $group['tier'] === 'likely' ? ['merge_ids' => $ids, 'target_id' => $group['target_id'], 'source' => 'finder'] : null);
        }

        $mixed = DB::table('manuscript_scribe as s')
            ->join('manuscripts as m', 'm.id', '=', 's.manuscript_id')
            ->whereNotNull('s.person_id')->whereNotNull('m.copy_date_hijri_year')
            ->groupBy('s.person_id')
            ->havingRaw('MAX(m.copy_date_hijri_year) - MIN(m.copy_date_hijri_year) > ?', [self::MIXED_SPAN])
            ->selectRaw('s.person_id, MIN(m.copy_date_hijri_year) lo, MAX(m.copy_date_hijri_year) hi, COUNT(*) n')
            ->get();
        foreach ($mixed as $row) {
            $key = 'person:' . $row->person_id;
            $seen['mixed'][] = $key;
            $this->upsert('mixed', $key, [(int) $row->person_id], null, "copies {$row->lo}–{$row->hi} ({$row->n})", null, $stats);
        }

        foreach ($seen as $type => $keys) {
            $stats['gone'] += PersonReview::where('type', $type)->whereIn('status', ['open', 'deferred'])
                ->whereNotIn('key', $keys ?: ['-'])->update(['status' => 'gone']);
        }

        return $stats;
    }

    /**
     * @param  array|null  $seed  a recorded judgement for a new item: decision (merge|skip|unsure), merge_ids, target_id, comment, source
     */
    protected function upsert(string $type, string $key, array $ids, ?string $tier, string $reason, ?array $seed, array &$stats, ?array $suggestion = null): void
    {
        $review = PersonReview::where('type', $type)->where('key', $key)->first();

        if (! $review) {
            $status = 'open';
            if ($seed) {
                $suggestion = array_filter([
                    'decision' => $seed['decision'] ?? null,
                    'merge_ids' => $seed['merge_ids'] ?? null,
                    'target_id' => $seed['target_id'] ?? null,
                    'comment' => $seed['comment'] ?? null,
                    'source' => $seed['source'] ?? 'model review',
                ], fn($v) => $v !== null && $v !== []);
                // An automatic «keep apart» is recorded as decided, and can be reopened from the panel
                if (($seed['decision'] ?? null) === 'skip') {
                    $status = 'distinct';
                }
            }
            PersonReview::create(compact('type', 'key', 'tier', 'reason', 'suggestion', 'status') + ['person_ids' => $ids]);
            $stats['created']++;

            return;
        }

        $known = (array) $review->person_ids;
        $newcomers = array_diff($ids, $known);
        $values = ['person_ids' => $ids, 'tier' => $tier, 'reason' => $reason];
        if (! $review->isOpen() && $newcomers && in_array($review->status, ['distinct', 'dismissed', 'merged', 'split'], true)) {
            $values['status'] = 'open';
            $stats['reopened']++;
        } elseif ($review->status === 'gone') {
            $values['status'] = 'open';
            $stats['reopened']++;
        } elseif ($known !== $ids) {
            $stats['updated']++;
        }
        $review->update($values);
    }
}
