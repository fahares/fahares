<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Exports of search results are logged beside the searches, for the search stats (backlog item 85) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_logs', function (Blueprint $table) {
            $table->string('kind', 8)->default('search')->after('scope');
            $table->string('format', 8)->nullable()->after('kind');
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('search_logs', function (Blueprint $table) {
            $table->dropIndex(['kind', 'created_at']);
            $table->dropColumn(['kind', 'format']);
        });
    }
};
