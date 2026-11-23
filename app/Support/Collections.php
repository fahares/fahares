<?php

namespace App\Support;

use App\Models\ManuscriptCollection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the «مجموعه» records in step with the copies (backlog item 163, step 14.322): a copy with a library and a
 * collection_key is of the collection of that library and key, which is made when the first copy names it; a copy
 * an editor placed (collection_pinned) stays where it is. A collection counts its copies and knows whether they are
 * treatises bound together or the volumes of one work, and one left with no copy is removed unless it holds
 * something of its own (ManuscriptCollection::hasOwnData).
 *
 * Only manuscripts.collection_id and the collections table are written: no other column of a copy, and nothing
 * the search index holds.
 */
class Collections
{
    /**
     * Brings the collections of these copies in step, or of every copy when none is named.
     *
     * @param  list<int>|null  $manuscriptIds
     * @return array{made: int, linked: int, unlinked: int, removed: int, kept_empty: int}
     */
    public static function sync(?array $manuscriptIds = null): array
    {
        $counts = ['made' => 0, 'linked' => 0, 'unlinked' => 0, 'removed' => 0, 'kept_empty' => 0];
        if ($manuscriptIds !== null) {
            $manuscriptIds = array_values(array_unique(array_filter(array_map('intval', $manuscriptIds))));
            if (! $manuscriptIds) {
                return $counts;
            }
        }
        $now = now();
        $touched = [];
        foreach ($manuscriptIds === null ? [null] : array_chunk($manuscriptIds, 1000) as $chunk) {
            $copies = fn () => DB::table('manuscripts')->when($chunk, fn ($q) => $q->whereIn('manuscripts.id', $chunk));
            if ($chunk) {
                array_push($touched, ...$copies()->whereNotNull('collection_id')->distinct()->pluck('collection_id')->all());
            }
            $wanted = $copies()->where('collection_pinned', false)->whereNotNull('library_id')->whereNotNull('collection_key')
                ->where('collection_key', '<>', '')->distinct()->select('manuscripts.library_id', 'manuscripts.collection_key AS key');
            $counts['made'] += DB::table('collections')->insertOrIgnoreUsing(['library_id', 'key', 'created_at', 'updated_at'],
                DB::query()->fromSub($wanted, 'w')->select('library_id', 'key')->selectRaw('?, ?', [$now, $now]));
            $counts['linked'] += $copies()->join('collections', fn ($j) => $j->on('collections.library_id', '=', 'manuscripts.library_id')->on('collections.key', '=', 'manuscripts.collection_key'))
                ->where('manuscripts.collection_pinned', false)
                ->where(fn ($q) => $q->whereNull('manuscripts.collection_id')->orWhereColumn('manuscripts.collection_id', '<>', 'collections.id'))
                ->update(['manuscripts.collection_id' => DB::raw('collections.id')]);
            $counts['unlinked'] += $copies()->where('collection_pinned', false)->whereNotNull('collection_id')
                ->where(fn ($q) => $q->whereNull('manuscripts.library_id')->orWhereNull('collection_key')->orWhere('collection_key', ''))
                ->update(['collection_id' => null]);
            if ($chunk) {
                array_push($touched, ...$copies()->whereNotNull('collection_id')->distinct()->pluck('collection_id')->all());
            }
        }

        foreach (self::recount($manuscriptIds === null ? null : $touched) as $kind => $n) {
            $counts[$kind] += $n;
        }

        return $counts;
    }

    /**
     * Counts the copies of these collections again (of all when none is named), sets their kind and removes the
     * empty ones that hold nothing of their own.
     *
     * @param  list<int>|null  $collectionIds
     * @return array{removed: int, kept_empty: int}
     */
    public static function recount(?array $collectionIds = null): array
    {
        $counts = ['removed' => 0, 'kept_empty' => 0];
        $collectionIds = $collectionIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $collectionIds))));
        foreach ($collectionIds === null ? [null] : array_chunk($collectionIds, 1000) as $chunk) {
            // the count, and the kind: two rows or more that are all one work are that work's volumes
            DB::table('collections')->when($chunk, fn ($q) => $q->whereIn('collections.id', $chunk))
                ->leftJoinSub(DB::table('manuscripts')->whereNotNull('collection_id')->when($chunk, fn ($q) => $q->whereIn('collection_id', $chunk))
                    ->groupBy('collection_id')->selectRaw('collection_id, COUNT(*) AS n, COUNT(DISTINCT work_id) AS works'), 'c', 'c.collection_id', '=', 'collections.id')
                ->update(['collections.copies_count' => DB::raw('COALESCE(c.n, 0)'),
                    'collections.kind' => DB::raw("CASE WHEN COALESCE(c.n, 0) >= 2 AND c.works = 1 THEN '" . ManuscriptCollection::SET . "' ELSE '" . ManuscriptCollection::BOUND . "' END")]);
            foreach (ManuscriptCollection::where('copies_count', 0)->when($chunk, fn ($q) => $q->whereIn('id', $chunk))->orderBy('id')->lazyById(500) as $empty) {
                if ($empty->hasOwnData()) {
                    $counts['kept_empty']++;
                } else {
                    $empty->delete();
                    $counts['removed']++;
                }
            }
        }

        return $counts;
    }
}
