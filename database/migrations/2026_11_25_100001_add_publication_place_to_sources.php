<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a source, or one volume of it, was printed: «تهران», «قم», «بیروت». The citation of a source page names it
 * before the publisher (step 14.389, backlog item 132).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('publication_place', 100)->nullable()->after('publisher');
        });
        Schema::table('source_volumes', function (Blueprint $table) {
            $table->string('publication_place', 100)->nullable()->after('publisher');
        });
    }

    public function down(): void
    {
        Schema::table('source_volumes', fn (Blueprint $table) => $table->dropColumn('publication_place'));
        Schema::table('sources', fn (Blueprint $table) => $table->dropColumn('publication_place'));
    }
};
