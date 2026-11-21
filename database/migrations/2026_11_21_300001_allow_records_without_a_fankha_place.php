<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A copy or work made in the panel from what a researcher saw (backlog item 149, step 14.306) stands in no volume of
 * Fankha: its catalog, volume and page are empty. Every record so far has them, so the columns were required.
 * An empty catalog_id is the sign the corpus commands and SourceEntrySync read: such a record has no Fankha entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['manuscripts', 'works'] as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY catalog_id BIGINT UNSIGNED NULL DEFAULT 1, MODIFY volume_number TINYINT UNSIGNED NULL, MODIFY page_start SMALLINT UNSIGNED NULL");
        }
    }

    public function down(): void
    {
        foreach (['manuscripts', 'works'] as $table) {
            if (DB::table($table)->whereNull('catalog_id')->orWhereNull('volume_number')->orWhereNull('page_start')->exists()) {
                throw new RuntimeException("{$table} holds records without a Fankha place; remove them before rolling back.");
            }
            DB::statement("ALTER TABLE {$table} MODIFY catalog_id BIGINT UNSIGNED NOT NULL DEFAULT 1, MODIFY volume_number TINYINT UNSIGNED NOT NULL, MODIFY page_start SMALLINT UNSIGNED NOT NULL");
        }
    }
};
