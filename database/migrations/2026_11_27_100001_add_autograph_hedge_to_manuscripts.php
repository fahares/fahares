<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cataloguer's doubt about an autograph copy (step 14.434, backlog item 52): «کا: = مؤلف (احتمالاً)» makes the copy
 * an autograph with the hedge «احتمالاً», which the copy's page shows beside «دستخط مؤلف». A nullable column at the end:
 * MariaDB adds it in place, without copying the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('manuscripts', 'autograph_hedge')) {
            Schema::table('manuscripts', function (Blueprint $table) {
                $table->string('autograph_hedge', 60)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('manuscripts', 'autograph_hedge')) {
            Schema::table('manuscripts', function (Blueprint $table) {
                $table->dropColumn('autograph_hedge');
            });
        }
    }
};
