<?php

namespace App\Services;

use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\PersonReview;
use App\Models\WorkPerson;
use Illuminate\Support\Facades\DB;

/**
 * Builds the admin queue «بازبینی اشخاص» (backlog item 78) from the data:
 *   duplicate — groups DuplicatePeopleFinder puts in the likely or review tier (conflicts are left out);
 *   mixed     — people whose copies surely lie more than MIXED_SPAN years apart (people.scribed_gap, step 14.430),
 *               likely several namesakes in one record. A copy dated by its century alone counts by its bounds.
 *               A scribe tied to a namesake author who died long before the copies stays in «تناقض تاریخ».
 * Namesake groups queued from a model review (key «judged:…», step 14.124) are kept until fewer than two of their
 * records are left.
 * Decisions stay with the key: a group judged «چند نفرند» is reopened only when a record it did not
 * have joins it; items whose records are gone are closed as «gone». Records split from one another (splitFamilies,
 * step 14.441) were judged several people: a group of them alone is queued as «چند نفرند», and one joining a group
 * that had its family reopens nothing.
 */
class PersonReviewQueue
{
    public const MIXED_SPAN = 70;

    /** Key prefix of namesake groups queued from a model review (fahares:queue-judged-people, step 14.124), not by the finder. */
    public const JUDGED = 'judged:';

    /** @return array<string, int> counts of what changed */
    public function refresh(array $seed = []): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'reopened' => 0, 'gone' => 0];
        $seen = ['duplicate' => [], 'mixed' => []];
        $family = self::splitFamilies();

        foreach (DuplicatePeopleFinder::groups()['groups'] as $group) {
            if ($group['tier'] === 'conflict') {
                continue;
            }
            $ids = array_map('intval', array_column($group['members'], 'id'));
            sort($ids);
            $seen['duplicate'][] = $group['key'];
            $this->upsert('duplicate', $group['key'], $ids, $group['tier'], $group['reason'], $seed[$group['key']] ?? null, $stats,
                $group['tier'] === 'likely' ? ['merge_ids' => $ids, 'target_id' => $group['target_id'], 'source' => 'finder'] : null, $family);
        }

        // As Person::scribeFacts' gap: the earliest end and the latest start of the copies with both bounds
        $mixed = DB::table('manuscript_people as s')->where('s.role', ManuscriptPerson::SCRIBE)
            ->join('manuscripts as m', 'm.id', '=', 's.manuscript_id')
            ->whereNotNull('s.person_id')->whereBetween('m.copy_date_from', [1, 1500])->whereBetween('m.copy_date_to', [1, 1500])
            ->groupBy('s.person_id')
            ->havingRaw('MAX(m.copy_date_from) - MIN(m.copy_date_to) > ?', [self::MIXED_SPAN])
            ->selectRaw('s.person_id, MIN(m.copy_date_to) lo, MAX(m.copy_date_from) hi, COUNT(*) n')
            ->get();
        foreach ($mixed as $row) {
            $key = 'person:' . $row->person_id;
            $seen['mixed'][] = $key;
            $this->upsert('mixed', $key, [(int) $row->person_id], null, "copies {$row->lo}–{$row->hi} ({$row->n})", null, $stats);
        }

        foreach ($seen as $type => $keys) {
            $stats['gone'] += PersonReview::where('type', $type)->whereIn('status', ['open', 'deferred'])
                ->whereNotIn('key', $keys ?: ['-'])->where('key', 'not like', self::JUDGED . '%')->update(['status' => 'gone']);
        }
        // A group queued from a model review stays until fewer than two of its records are left
        PersonReview::where('type', 'duplicate')->whereIn('status', ['open', 'deferred'])->where('key', 'like', self::JUDGED . '%')
            ->each(function (PersonReview $review) use (&$stats) {
                $left = Person::whereIn('id', (array) $review->person_ids)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                if (count($left) < 2) {
                    $review->update(['status' => 'gone']);
                    $stats['gone']++;
                } elseif ($left !== array_values((array) $review->person_ids)) {
                    $review->update(['person_ids' => $left]);
                    $stats['updated']++;
                }
            });

        return $stats;
    }

    /**
     * The record each person was split from, followed back to the first (AuditLog «split» of a person, whose
     * old_values.created_id is the new record): the people of one family were judged several people, so the
     * namesake group they make is not a question again (step 14.441).
     *
     * @return array<int, int> person id => the id of the record its family began with
     */
    public static function splitFamilies(): array
    {
        $parent = [];
        \App\Models\AuditLog::where('action', 'split')->where('auditable_type', Person::class)
            ->get(['auditable_id', 'old_values'])
            ->each(function ($log) use (&$parent) {
                if ($created = (int) ($log->old_values['created_id'] ?? 0)) {
                    $parent[$created] = (int) $log->auditable_id;
                }
            });
        $root = [];
        foreach (array_keys($parent) as $id) {
            $r = $id;
            for ($guard = 0; isset($parent[$r]) && $guard < 50; $guard++) {
                $r = $parent[$r];
            }
            $root[$id] = $r;
        }

        return $root;
    }

    /**
     * @param  array|null  $seed  a recorded judgement for a new item: decision (merge|skip|unsure), merge_ids, target_id, comment, source
     * @param  array<int, int>  $family  splitFamilies(): records split from one another make no question
     */
    protected function upsert(string $type, string $key, array $ids, ?string $tier, string $reason, ?array $seed, array &$stats, ?array $suggestion = null, array $family = []): void
    {
        $review = PersonReview::where('type', $type)->where('key', $key)->first();
        $root = fn (int $id) => $family[$id] ?? $id;

        if (! $review) {
            $status = 'open';
            // Records split from one another (step 14.441) were judged several people: such a group is no new question
            if ($family && count(array_unique(array_map($root, $ids))) === 1) {
                $status = 'distinct';
                $reason .= '; split from one another';
            }
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
        // a record split from one the group had is no newcomer to judge
        $families = array_map($root, $known);
        $newcomers = array_filter($newcomers, fn ($id) => ! in_array($root($id), $families, true));
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

    /**
     * What a reviewer, or a researcher in «تصحیح جمعی», needs to judge each person: dates, counts, and samples of their works and copies
     * (all copies, in date order, for a record that may hold several people).
     */
    public function profiles(array $ids, bool $allCopies): array
    {
        if (! $ids) {
            return [];
        }
        $people = Person::whereIn('id', $ids)->get(['id', 'name', 'aliases', 'death_year_hijri', 'century_hijri', 'century_from', 'works_count', 'manuscripts_count'])->keyBy('id');

        $copies = DB::table('manuscript_people as s')->where('s.role', ManuscriptPerson::SCRIBE)
            ->join('manuscripts as m', 'm.id', '=', 's.manuscript_id')
            ->leftJoin('works as w', 'w.id', '=', 'm.work_id')
            ->whereIn('s.person_id', $ids)
            // a copy dated by its century alone by the middle of its bounds, among the copies dated by a year
            ->orderBy('s.person_id')->orderByRaw('COALESCE(m.copy_date_hijri_year, (m.copy_date_from + m.copy_date_to) / 2) IS NULL, COALESCE(m.copy_date_hijri_year, (m.copy_date_from + m.copy_date_to) / 2), m.id')
            ->get(['s.person_id', 'm.id', 'm.city', 'm.library', 'm.shelfmark', 'm.copy_date_hijri_year as year', 'm.copy_date_from as date_from', 'm.copy_date_to as date_to',
                'm.scribe_name', 'w.primary_title as title'])
            ->groupBy('person_id');
        $works = DB::table('work_people as a')->where('a.role', WorkPerson::AUTHOR)->join('works as w', 'w.id', '=', 'a.work_id')
            ->whereIn('a.person_id', $ids)->get(['a.person_id', 'w.id', 'w.primary_title as title'])->groupBy('person_id');

        $out = [];
        foreach ($ids as $id) {
            $p = $people[$id] ?? null;
            if (! $p) {
                continue;
            }
            $c = $copies[$id] ?? collect();
            $starts = $c->map(fn ($copy) => $copy->year ?: self::bound($copy->date_from))->filter();
            $ends = $c->map(fn ($copy) => $copy->year ?: self::bound($copy->date_to))->filter();
            $out[$id] = [
                'person' => $p,
                'span' => $starts->isNotEmpty() && $ends->isNotEmpty() ? [$starts->min(), $ends->max()] : null,
                'copies' => $allCopies ? $c->take(400)->values() : $c->take(4)->values(),
                'works' => ($works[$id] ?? collect())->take($allCopies ? 20 : 3)->values(),
            ];
        }

        return $out;
    }

    /** When a copy of a profile was made: its year, else its century or the bounds of its date («سدۀ ۱۳», «۱۰۵۰–۱۱۲۰»). */
    public static function copyWhen(object $copy): ?string
    {
        [$from, $to] = [self::bound($copy->date_from ?? null), self::bound($copy->date_to ?? null)];
        $text = match (true) {
            (bool) $copy->year => "{$copy->year} ق",
            ! $from || ! $to => null,
            $from === $to => "{$from} ق",
            $from % 100 === 1 && $to === $from + 99 => 'سدۀ ' . intdiv($to, 100),
            default => "{$from}–{$to}",
        };

        return $text === null ? null : \App\Support\Fa::digits($text);
    }

    /** A bound of a copy's date as Person::scribeFacts keeps it: a year from 1 to 1500. */
    private static function bound(mixed $year): ?int
    {
        return $year !== null && $year >= 1 && $year <= 1500 ? (int) $year : null;
    }
}
