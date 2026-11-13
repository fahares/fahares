<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The years a scribe's dated copies lie between (step 14.155): almost no scribe has a death date, so the search tab
 * of scribes shows and orders them by when they copied. Filled by Person::recountContributions
 * (fahares:sync-person-roles --apply for the people already there).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->smallInteger('scribed_from')->nullable()->after('manuscripts_count');
            $table->smallInteger('scribed_to')->nullable()->after('scribed_from');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['scribed_from', 'scribed_to']);
        });
    }
};
