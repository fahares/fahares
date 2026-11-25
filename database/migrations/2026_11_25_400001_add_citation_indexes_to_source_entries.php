<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the copies of a cataloger, a catalogue volume or a part, read from the copies' citation entries since
 * step 14.402 (backlog item 170): each holds the copy's id, so the entries' wide rows are not read. Built in place
 * without a lock (7 s in all on the production copy), and only when missing, so a deploy stays short.
 */
return new class extends Migration
{
    private const INDEXES = [
        'se_citation_cataloger' => 'cataloger_id',
        'se_citation_part' => 'catalog_volume_part_id',
        'se_citation_volume' => 'source_volume_id',
    ];

    /** A copy's or a work's Fankha place by its record, for Fankha's order of a list of copies read on the entries. */
    private const PLACE = 'se_fankha_place';

    public function up(): void
    {
        $have = collect(DB::select('SHOW INDEX FROM source_entries'))->pluck('Key_name')->unique()->all();
        $add = [];
        foreach (self::INDEXES as $name => $column) {
            if (! in_array($name, $have, true)) {
                $add[] = "ADD INDEX {$name} ({$column}, legacy_type, legacy_index, legacy_id)";
            }
        }
        if (! in_array(self::PLACE, $have, true)) {
            $add[] = 'ADD INDEX ' . self::PLACE . ' (legacy_type, legacy_id, volume_number, page_start)';
        }
        $add && DB::statement('ALTER TABLE source_entries ' . implode(', ', $add) . ', ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(): void
    {
        // the foreign keys of cataloger_id and catalog_volume_part_id may use these indexes; they stay
    }
};
