<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The record's own source columns go (step 14.407, backlog item 170, phase 5): a work's or a copy's place and text in
 * Fankha, a work's bibliography, a copy's row, its catalogue volume, part and cataloger. Since phases 1 to 4 the
 * source entries hold them all (source_entries legacy_type work, manuscript, work_bibliography, fankha_citation) and
 * nothing reads or writes these columns. Production's values were exported before the drop to
 * fahares-sources/backups/step13/.
 *
 * Foreign keys first, then the columns in one statement per table. A column only goes when it is there, so a deploy
 * that is rolled back half-way can run it again.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'works' => ['catalog_id', 'volume_number', 'page_start', 'page_end', 'raw_text', 'bibliography'],
        'manuscripts' => ['catalog_id', 'volume_number', 'page_start', 'page_end', 'raw_text', 'sequence_number',
            'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $columns = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if (! $columns) {
                continue;
            }
            $keys = DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)
                ->whereIn('COLUMN_NAME', $columns)->whereNotNull('REFERENCED_TABLE_NAME')->pluck('CONSTRAINT_NAME')->unique();
            foreach ($keys as $key) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$key}`");
            }
            // an index of a dropped column and another (manuscripts' work_id, sequence_number) goes with it
            $indexes = DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)
                ->whereIn('COLUMN_NAME', $columns)->where('INDEX_NAME', '!=', 'PRIMARY')->pluck('INDEX_NAME')->unique();
            $drops = [...$indexes->map(fn ($i) => "DROP INDEX `{$i}`"), ...array_map(fn ($c) => "DROP COLUMN `{$c}`", $columns)];
            DB::statement("ALTER TABLE `{$table}` " . implode(', ', $drops));
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('The source columns come back only from the export in fahares-sources/backups/step13/.');
    }
};
