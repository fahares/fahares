<?php

namespace Tests\Support;

use App\Models\Manuscript;
use App\Models\Work;
use App\Sources\RecordEntries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Inserts a work or a copy with the query builder (no model hook), as the corpus commands' tests do, with the
 * source values under their old names (volume_number, page_start, raw_text, sequence_number, catalog_volume_id …)
 * written in the record's entries, since the columns are gone (step 14.407).
 */
class InsertRecords
{
    public static function work(array $columns): int
    {
        return self::insert(new Work, $columns);
    }

    public static function copy(array $columns): int
    {
        return self::insert(new Manuscript, $columns);
    }

    /** Writes columns and old-named source values on a work with the query builder (RecordEntries::write). */
    public static function updateWork(int $id, array $columns): void
    {
        RecordEntries::write((new Work)->forceFill(['id' => $id]), $columns);
    }

    public static function updateCopy(int $id, array $columns): void
    {
        RecordEntries::write((new Manuscript)->forceFill(['id' => $id]), $columns);
    }

    private static function insert(Model $model, array $columns): int
    {
        // a copy's catalogue citation given in its metadata is its citation entry's (step 14.413)
        $model instanceof Manuscript && $columns = RecordEntries::liftCitation($columns);
        $source = array_intersect_key($columns, array_flip($model::SOURCE_ATTRIBUTES));
        $id = DB::table($model->getTable())->insertGetId(array_diff_key($columns, $source));
        $source && RecordEntries::take($model->forceFill(['id' => $id]), $source, true);

        return $id;
    }
}
