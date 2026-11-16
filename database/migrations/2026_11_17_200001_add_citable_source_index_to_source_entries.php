<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sources of each record, read from the index alone (step 14.228): the insights page counts the catalogues
     * that describe each copy, and through the index of the record alone it reads every entry's row for its source.
     */
    public function up(): void
    {
        // The local copy of the production data got it by hand when the figures were timed
        if (Schema::hasIndex('source_entries', 'source_entries_citable_source')) {
            return;
        }
        Schema::table('source_entries', function (Blueprint $table) {
            $table->index(['citable_type', 'citable_id', 'source_id', 'status'], 'source_entries_citable_source');
        });
    }

    public function down(): void
    {
        Schema::table('source_entries', function (Blueprint $table) {
            $table->dropIndex('source_entries_citable_source');
        });
    }
};
