<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A copy's entry in the library catalogue Fankha cites holds the part of the volume it is in and the cataloger who
 * described it (step 14.401, backlog item 170, phase 4). Until now these were the copy's own columns
 * (manuscripts.catalog_volume_part_id, cataloger_id); the cataloger is not always the volume's or the part's first
 * (a volume two catalogers shared copy by copy), so it is the entry's, not derived.
 *
 * Only the columns and their foreign keys: the copies' values are copied in by `sources:fill-catalogue-entries`
 * after the deploy (step 14.403). The first deploy of this migration filled them here, rebuilt the table for 77 s,
 * outlasted the healthcheck and was rolled back with the columns made and nothing filled; so each part is made only
 * when it is not there yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['catalog_volume_part_id' => 'catalog_volume_parts', 'cataloger_id' => 'catalogers'] as $column => $table) {
            if (! Schema::hasColumn('source_entries', $column)) {
                Schema::table('source_entries', fn (Blueprint $t) => $t->foreignId($column)->nullable()->after('source_volume_id'));
            }
            if (! $this->hasForeignKey($column)) {
                Schema::table('source_entries', fn (Blueprint $t) => $t->foreign($column)->references('id')->on($table)->nullOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (['cataloger_id', 'catalog_volume_part_id'] as $column) {
            if (Schema::hasColumn('source_entries', $column)) {
                Schema::table('source_entries', fn (Blueprint $t) => $t->dropConstrainedForeignId($column));
            }
        }
    }

    private function hasForeignKey(string $column): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'source_entries')->where('COLUMN_NAME', $column)->whereNotNull('REFERENCED_TABLE_NAME')->exists();
    }
};
