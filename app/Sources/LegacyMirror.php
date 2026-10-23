<?php

namespace App\Sources;

use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\SourceEntry;
use Illuminate\Support\Facades\DB;

/**
 * Writes a correction or a review of an entry back to the manuscript_catalog_references row it was copied from
 * (step 13.3), since catalogs:match-edition still reads that table to leave the reviewed rows alone. It goes with
 * the matcher's move to source_entries (step 13.4).
 */
class LegacyMirror
{
    private const ORIGINS = [
        SourceEntry::ORIGIN_MATCH => Ref::SOURCE_AI_MATCH,
        SourceEntry::ORIGIN_MANUAL => Ref::SOURCE_MANUAL,
        SourceEntry::ORIGIN_FANKHA_CITATION => Ref::SOURCE_FANKHA,
    ];

    public static function reference(SourceEntry $entry): void
    {
        if ($entry->legacy_type !== 'catalog_reference') {
            return;
        }
        DB::table('manuscript_catalog_references')->where('id', $entry->legacy_id)->update([
            'volume_number' => $entry->volume_number,
            'page_start' => $entry->page_start,
            'page_end' => $entry->page_end,
            'source' => self::ORIGINS[$entry->origin] ?? Ref::SOURCE_MANUAL,
            'status' => $entry->status,
            'reviewed_by' => $entry->reviewed_by,
            'reviewed_at' => $entry->reviewed_at,
            'updated_at' => now(),
        ]);
    }

    /** The same review columns for the rows a set of entries came from. */
    public static function reviews(array $entryIds, array $values): void
    {
        foreach (array_chunk($entryIds, 1000) as $chunk) {
            $legacy = DB::table('source_entries')->whereIn('id', $chunk)->where('legacy_type', 'catalog_reference')->pluck('legacy_id');
            DB::table('manuscript_catalog_references')->whereIn('id', $legacy)->update($values);
        }
    }
}
