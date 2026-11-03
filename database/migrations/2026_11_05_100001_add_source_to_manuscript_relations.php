<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a relation between copies came from (step 14.16): «suggestion» (a reader's suggestion) or «fankha» (the
     * catalogue's «همان نسخه بالا», linked by fahares:link-copy-references, which reverts its own rows by it).
     */
    public function up(): void
    {
        Schema::table('manuscript_relations', function (Blueprint $table) {
            $table->string('source', 20)->default('suggestion')->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('manuscript_relations', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
