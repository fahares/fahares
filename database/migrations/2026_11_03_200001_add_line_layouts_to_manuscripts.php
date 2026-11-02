<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A copy's line layouts (item 87, step 13.156; App\Support\CopyLines). lines_raw keeps the catalogue's wording
     * («18 و 21 سطر»), line_counts each layout as [from, to]: [[18, 18], [21, 21]] for two layouts, [[15, 22]] for
     * one whose count varies. lines stays the single count the site shows and sorts by.
     */
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->string('lines_raw', 255)->nullable()->after('lines');
            $table->json('line_counts')->nullable()->after('lines_raw');
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropColumn(['lines_raw', 'line_counts']);
        });
    }
};
