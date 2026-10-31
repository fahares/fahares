<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person's life dates as the catalogue writes them («1040 - 1124 ؟ قمری», «ق13 قمری») and the death they give as
     * a range of Hijri years with its hedge, by the rules of a copy's date (CopyDateRange; backlog item 96, «همین الگو
     * برای وفات شخص»). death_year_hijri stays the chosen year.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('life_dates_raw', 120)->nullable()->after('death_year_gregorian');
            $table->smallInteger('death_from')->nullable()->after('life_dates_raw');
            $table->smallInteger('death_to')->nullable()->after('death_from');
            $table->string('death_certainty', 10)->nullable()->after('death_to');
            $table->string('death_hedge_raw', 60)->nullable()->after('death_certainty');
            $table->index(['death_from', 'death_to']);
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['death_from', 'death_to']);
            $table->dropColumn(['life_dates_raw', 'death_from', 'death_to', 'death_certainty', 'death_hedge_raw']);
        });
    }
};
