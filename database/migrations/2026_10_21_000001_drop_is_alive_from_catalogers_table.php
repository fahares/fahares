<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never shown on the site, and its values came from unsourced generated profiles (backlog item 44)
        Schema::table('catalogers', function (Blueprint $table) {
            $table->dropColumn('is_alive');
        });
    }

    public function down(): void
    {
        Schema::table('catalogers', function (Blueprint $table) {
            $table->boolean('is_alive')->default(false)->after('death_year_hijri');
        });
    }
};
