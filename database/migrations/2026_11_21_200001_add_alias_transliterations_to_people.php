<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transliteration of each of a person's other names (step 14.299): a map from the name to its transliteration,
 * beside `aliases`, which stays a list of names for everything that reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->json('alias_transliterations')->nullable()->after('aliases');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('alias_transliterations');
        });
    }
};
