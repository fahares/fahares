<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misspelt forms of a person's name (backlog item 90): kept for search and redirects, not shown among the other
 * names. A merge whose removed name was wrong puts it here instead of in aliases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->json('wrong_names')->nullable()->after('aliases');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('wrong_names');
        });
    }
};
