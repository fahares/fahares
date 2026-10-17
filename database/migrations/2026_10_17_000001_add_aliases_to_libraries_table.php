<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Other names of a library: the former name after a rename, or the name of a duplicate record
     * folded into it. Older catalogs and studies cite libraries by these names.
     */
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->json('aliases')->nullable()->after('full_name');
        });
    }

    public function down(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
