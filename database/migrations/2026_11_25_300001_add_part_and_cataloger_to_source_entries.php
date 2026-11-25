<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A copy's entry in the library catalogue Fankha cites holds the part of the volume it is in and the cataloger who
 * described it (step 14.401, backlog item 170, phase 4). Until now these were the copy's own columns
 * (manuscripts.catalog_volume_part_id, cataloger_id); the cataloger is not always the volume's or the part's first
 * (a volume two catalogers shared copy by copy), so it is the entry's, not derived. The copies' values are copied in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_entries', function (Blueprint $table) {
            $table->foreignId('catalog_volume_part_id')->nullable()->after('source_volume_id')->constrained('catalog_volume_parts')->nullOnDelete();
            $table->foreignId('cataloger_id')->nullable()->after('catalog_volume_part_id')->constrained('catalogers')->nullOnDelete();
        });
        DB::statement("UPDATE source_entries e JOIN manuscripts m ON m.id = e.legacy_id
            SET e.catalog_volume_part_id = m.catalog_volume_part_id, e.cataloger_id = m.cataloger_id
            WHERE e.legacy_type = 'fankha_citation' AND e.legacy_index = 0");
    }

    public function down(): void
    {
        Schema::table('source_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cataloger_id');
            $table->dropConstrainedForeignId('catalog_volume_part_id');
        });
    }
};
