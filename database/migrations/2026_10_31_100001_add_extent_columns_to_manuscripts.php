<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4 of the hybrid data-structure roadmap (backlog item 97, the user's choice of 13.65's decision 3).
     * folios stays the count of leaves; pages is the count of pages (a page count is never turned into leaves).
     * extent_raw keeps the catalogue's wording («10گ (34پ-43ر)»), and range_from/range_to the leaves or pages the
     * copy takes in a volume as written («34پ», «43ر»), with their numbers for sorting and search.
     */
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->unsignedSmallInteger('pages')->nullable()->after('folios');
            $table->string('extent_raw', 120)->nullable()->after('pages');
            $table->string('range_from', 12)->nullable()->after('extent_raw');
            $table->string('range_to', 12)->nullable()->after('range_from');
            $table->unsignedSmallInteger('range_from_number')->nullable()->after('range_to');
            $table->unsignedSmallInteger('range_to_number')->nullable()->after('range_from_number');
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropColumn(['pages', 'extent_raw', 'range_from', 'range_to', 'range_from_number', 'range_to_number']);
        });
    }
};
