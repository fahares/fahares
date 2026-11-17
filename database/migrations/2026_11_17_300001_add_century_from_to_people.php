<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The centuries of a life (step 14.247): century_hijri stays the one century the lists order by, the death's
     * when there is a death year, and century_from is the first century of the life when it is an earlier one
     * (born in 970 and dead in 1050: 10, beside century_hijri 11). Null for a life within one century.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->tinyInteger('century_from')->nullable()->after('century_hijri');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('century_from');
        });
    }
};
