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
     * @return array{made: int, linked: int, unlinked: int, removed: int, kept_empty: int, values: int}
     */
    public static function sync(?array $manuscriptIds = null): array
    {
        $counts = ['made' => 0, 'linked' => 0, 'unlinked' => 0, 'removed' => 0, 'kept_empty' => 0, 'values' => 0];
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
        // what the volumes are said to be follows what their treatises say (step 14.323)
        $counts['values'] = self::derive($manuscriptIds === null ? null : $touched)['collections'];

        return $counts;
    }

    /**
     * Works out the values of these collections from their treatises again (CollectionValues), of all when none is
     * named, and writes what differs; with $write false it only counts.
     *
     * @param  list<int>|null  $collectionIds
     * @return array{collections: int, fields: array<string, int>} the collections and, by field, the values that change
     */
    public static function derive(?array $collectionIds = null, bool $write = true): array
    {
        $report = ['collections' => 0, 'fields' => []];
        $collectionIds = $collectionIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $collectionIds))));
        if ($collectionIds === []) {
            return $report;
        }
        $changed = [];
        ManuscriptCollection::query()->when($collectionIds, fn ($q) => $q->whereIn('id', $collectionIds))->orderBy('id')
            ->chunkById(500, function ($collections) use (&$report, $write, &$changed) {
                $copies = DB::table('manuscripts')->whereIn('collection_id', $collections->modelKeys())->orderBy('id')->get(CollectionValues::COPY_COLUMNS)->groupBy('collection_id');
                foreach ($collections as $collection) {
                    $change = self::valuesChange($collection, $copies[$collection->id] ?? collect());
                    if (! $change) {
                        continue;
                    }
                    $report['collections']++;
                    foreach ($change['fields'] as $field) {
                        $report['fields'][$field] = ($report['fields'][$field] ?? 0) + 1;
                    }
                    if ($write) {
                        DB::table('collections')->where('id', $collection->id)->update($change['columns'] + ['updated_at' => now()]);
                        $changed[] = $collection->id;
                    }
                }
            });
        if ($write) {
            // what the treatises read of these collections, whether or not a value changed: a treatise that now
            // names its own hand reads the volume's no more
            self::refreshReadings($collectionIds);
        }
        self::reindex($changed);

        return $report;
    }

    /**
     * Makes the rows of collection_readings of these collections again (of all when none is named): what each of
     * their treatises reads from its collection, for the filters asked of the database (CollectionIndex, step 14.329).
     *
     * @param  list<int>|null  $collectionIds
     */
    public static function refreshReadings(?array $collectionIds = null): int
    {
        CollectionIndex::flush();
        $rows = 0;
        $collectionIds = $collectionIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $collectionIds))));
        $each = function (array $ids) use (&$rows) {
            $made = [];
            foreach (\App\Models\Manuscript::whereIn('collection_id', $ids)->with(['scripts:id,name', 'scribes:people.id', 'notes:id,manuscript_id,type,also'])->orderBy('id')->get() as $copy) {
                foreach (CollectionIndex::readings($copy) as $reading) {
                    $made[] = ['collection_id' => $copy->collection_id, 'manuscript_id' => $copy->id] + $reading;
                }
            }
            DB::transaction(function () use ($ids, $made) {
                DB::table('collection_readings')->whereIn('collection_id', $ids)->delete();
                foreach (array_chunk($made, 1000) as $chunk) {
                    DB::table('collection_readings')->insert($chunk);
                }
            });
            $rows += count($made);
            CollectionIndex::flush();
        };
        if ($collectionIds !== null) {
            foreach (array_chunk($collectionIds, 200) as $ids) {
                $each($ids);
            }

            return $rows;
        }
        DB::table('collections')->orderBy('id')->select('id')->chunkById(200, fn ($chunk) => $each($chunk->pluck('id')->all()));

        return $rows;
    }

    /** More collections than this changed at once are a run of a command: fahares:index-collections follows it */
    public const REINDEX_AT_ONCE = 25;

    /**
     * Sends the treatises of collections whose values changed to the search index again, since their documents hold
     * what they read from the collection (CollectionIndex, step 14.329). A handful is sent at once; after a run that
     * changes more, fahares:index-collections sends them all.
     *
     * @param  list<int>  $collectionIds
     */
    public static function reindex(array $collectionIds): void
    {
        CollectionIndex::flush();
        if (! $collectionIds || count($collectionIds) > self::REINDEX_AT_ONCE || \Laravel\Scout\ModelObserver::syncingDisabledFor(\App\Models\Manuscript::class)) {
            return;
        }
        \App\Models\Manuscript::whereIn('collection_id', $collectionIds)->get()->searchable();
    }

    /**
     * The columns of a collection that differ from what its copies give, and the fields they are of; null when none.
     * A field an editor or a catalogue gave keeps its value and basis.
     *
     * @return array{columns: array<string, mixed>, fields: list<string>}|null
     */
    public static function valuesChange(ManuscriptCollection $collection, iterable $copies): ?array
    {
        $derived = CollectionValues::derive($copies, $collection->kind);
        $basis = $collection->basis ?? [];
        $columns = [];
        $fields = [];
        foreach (CollectionValues::fields() as $field) {
            if (in_array($basis[$field] ?? null, CollectionValues::GIVEN, true)) {
                continue;
            }
            $changed = ($basis[$field] ?? null) !== ($derived['basis'][$field] ?? null);
            foreach (CollectionValues::columnsOf($field) as $column) {
                if ((string) ($collection->getRawOriginal($column) ?? '') !== (string) ($derived['values'][$column] ?? '')) {
                    $columns[$column] = $derived['values'][$column];
                    $changed = true;
                }
            }
            if ($changed) {
                $fields[] = $field;
            }
            if (isset($derived['basis'][$field])) {
                $basis[$field] = $derived['basis'][$field];
            } else {
                unset($basis[$field]);
            }
        }
        if (! $fields) {
            return null;
        }

        return ['columns' => $columns + ['basis' => $basis ? json_encode($basis, JSON_UNESCAPED_UNICODE) : null], 'fields' => $fields];
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
