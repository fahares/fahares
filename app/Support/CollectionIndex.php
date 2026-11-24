<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use App\Models\ManuscriptCollection;
use App\Models\ManuscriptNote;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a copy's search document holds of its «مجموعه» (backlog item 163, step 14.329): the values a treatise reads
 * from its collection (Manuscript::inheritsFromCollection) and the volume's own, so that a search for a hand or a
 * scribe, and the filters of the manuscripts tab, find the treatise whose own entry is silent. Nothing of it is
 * written in the copy's columns.
 *
 * The same reading stands in three places and must stay one: fields() for the search document (the Meilisearch
 * form of the filters), copies() over the derived rows of collection_readings for the filters asked of the database (SearchFilters::apply), and
 * the copy's page.
 */
class CollectionIndex
{
    /** The fields of a copy's search document this class gives */
    public const FIELDS = ['collection_id', 'inherited', 'scribe_name', 'scribe_ids', 'script_names', 'script_style', 'scripts', 'lines', 'dimensions', 'paper', 'binding', 'note_types'];

    private const KEY = 'collection-index.volumes';

    /**
     * What is known of the collections read so far, for the request or the queued job (a scoped instance of the
     * container, as App\Support\WorkParts keeps its map).
     */
    private static function known(): \ArrayObject
    {
        if (! app()->bound(self::KEY)) {
            app()->scoped(self::KEY, fn () => new \ArrayObject);
        }

        return app(self::KEY);
    }

    /** After a collection's values or its treatises' notes changed */
    public static function flush(): void
    {
        app()->forgetInstance(self::KEY);
    }

    /**
     * The fields of FIELDS for a copy: its own values, the collection's where the copy reads them, and the types of
     * the provenance notes of the volume's other treatises beside its own.
     */
    public static function fields(Manuscript $copy): array
    {
        $fields = [
            'collection_id' => $copy->collection_id ? (int) $copy->collection_id : null,
            'inherited' => [],
            'scribe_name' => TextNormalizer::normalize($copy->scribe_name ?: ($copy->is_autograph ? 'مؤلف' : null)),
            'scribe_ids' => $copy->scribes->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'script_names' => $copy->script_names,
            'script_style' => $copy->script_style,
            'scripts' => $copy->scripts->pluck('name')->all(),
            'lines' => $copy->lines ? (int) $copy->lines : null,
            'dimensions' => $copy->dimensions,
            'paper' => $copy->paper,
            'binding' => $copy->binding,
            'note_types' => ManuscriptNote::typesOf($copy->notes),
        ];
        $volume = $copy->collection_id ? self::volume((int) $copy->collection_id) : null;
        if (! $volume) {
            return $fields;
        }
        $collection = $volume['collection'];
        $copy->setRelation('collection', $collection);
        foreach (CollectionValues::OWN as $field) {
            $fields[$field] = filled($collection->{$field}) ? $collection->{$field} : $fields[$field];
        }
        if ($copy->inheritsFromCollection('script_names')) {
            $fields = ['script_names' => $collection->script_names, 'script_style' => $collection->script_style, 'scripts' => $volume['scripts']] + $fields;
            $fields['inherited'][] = 'script_names';
        }
        if ($copy->inheritsFromCollection('lines')) {
            $fields['lines'] = $collection->lines ? (int) $collection->lines : null;
            $fields['inherited'][] = 'lines';
        }
        if ($copy->inheritsFromCollection('scribe')) {
            $fields['scribe_name'] = TextNormalizer::normalize($collection->scribe_name ?: $collection->scribe?->name);
            if ($collection->scribe_id) {
                $fields['scribe_ids'] = array_values(array_unique([...$fields['scribe_ids'], (int) $collection->scribe_id]));
            }
            $fields['inherited'][] = 'scribe';
        }
        $types = array_flip([...$fields['note_types'], ...$volume['note_types']]);
        $fields['note_types'] = array_values(array_filter(array_keys(ManuscriptNote::TYPES), fn ($type) => isset($types[$type])));

        return $fields;
    }

    /**
     * A collection its treatises read from (bound, of two or more), with the scripts its hand stands for and the
     * types of its treatises' provenance notes; null for any other. Kept for the request or the run.
     */
    private static function volume(int $id): ?array
    {
        $known = self::known();
        if ($known->offsetExists($id)) {
            return $known[$id];
        }
        $collection = ManuscriptCollection::find($id);
        if (! $collection || $collection->kind !== ManuscriptCollection::BOUND || $collection->copies_count < 2) {
            return $known[$id] = null;
        }
        $basis = $collection->basis ?? [];
        // the scripts of a treatise that names the hand as the collection does
        $scripts = [];
        if (isset($basis['script_names'])) {
            $stating = DB::table('manuscripts')->where('collection_id', $id)->whereNotNull('script_names')->orderBy('id')->get(['id', 'script_names'])
                ->first(fn ($m) => CollectionValues::folded($m->script_names) === CollectionValues::folded($collection->script_names));
            $scripts = $stating ? DB::table('manuscript_script')->join('scripts', 'scripts.id', '=', 'manuscript_script.script_id')
                ->where('manuscript_script.manuscript_id', $stating->id)->orderByDesc('manuscript_script.is_primary')->pluck('scripts.name', 'scripts.id')->all() : [];
        }
        $provenance = ManuscriptNote::GROUPS['provenance']['types'];
        $types = array_values(array_intersect(ManuscriptNote::typesOf(DB::table('manuscript_notes')
            ->whereIn('manuscript_id', DB::table('manuscripts')->where('collection_id', $id)->select('id'))
            ->where(fn ($q) => $q->whereIn('type', $provenance)->orWhereNotNull('also'))->get(['type', 'also'])), $provenance));

        return $known[$id] = ['collection' => $collection, 'scripts' => array_values($scripts), 'script_ids' => array_map('intval', array_keys($scripts)), 'note_types' => $types];
    }

    /**
     * The rows of collection_readings of a copy: what its search document holds that is not its own.
     *
     * @return list<array{kind: string, ref: string}>
     */
    public static function readings(Manuscript $copy): array
    {
        $volume = $copy->collection_id ? self::volume((int) $copy->collection_id) : null;
        if (! $volume) {
            return [];
        }
        $fields = self::fields($copy);
        $rows = [];
        if (in_array('scribe', $fields['inherited'], true) && $volume['collection']->scribe_id) {
            $rows[] = ['kind' => 'scribe', 'ref' => (string) $volume['collection']->scribe_id];
        }
        foreach (in_array('script_names', $fields['inherited'], true) ? $volume['script_ids'] : [] as $scriptId) {
            $rows[] = ['kind' => 'script', 'ref' => (string) $scriptId];
        }
        foreach (array_diff($fields['note_types'], ManuscriptNote::typesOf($copy->notes)) as $type) {
            $rows[] = ['kind' => 'note', 'ref' => $type];
        }

        return $rows;
    }

    /**
     * The ids of the copies that have this of their own ($own, a query of one column `manuscript_id`) together with
     * those that read it from their collection (a scribe, a script, a type of provenance note), as their search
     * documents do. One list for `whereIn`: asked as two lists joined by «or», the database took 29 seconds where
     * this takes milliseconds (step 14.329).
     */
    public static function with(string $kind, int|string $ref, Builder $own): Builder
    {
        return DB::query()->fromSub($own->union(DB::table('collection_readings')->where('kind', $kind)->where('ref', (string) $ref)->select('manuscript_id')), 'having')
            ->select('having.manuscript_id');
    }
}
