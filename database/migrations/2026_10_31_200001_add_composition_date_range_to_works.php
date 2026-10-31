<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A work's date of composition as a range of Hijri years with the catalogue's hedge word, read from
     * composition_date_raw by the rules of a copy's date (CopyDateRange; backlog item 96, «همان الگو برای composition_*»).
     */
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->smallInteger('composition_date_from')->nullable()->after('composition_year_hijri');
            $table->smallInteger('composition_date_to')->nullable()->after('composition_date_from');
            $table->string('composition_date_certainty', 10)->nullable()->after('composition_date_to');
            $table->string('composition_date_hedge_raw', 60)->nullable()->after('composition_date_certainty');
            $table->index(['composition_date_from', 'composition_date_to']);
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropIndex(['composition_date_from', 'composition_date_to']);
            $table->dropColumn(['composition_date_from', 'composition_date_to', 'composition_date_certainty', 'composition_date_hedge_raw']);
        });
    }
};
