<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            if (!Schema::hasColumn('works', 'incipit_text')) {
                $table->text('incipit_text')->nullable()->after('language_summary');
            }
            if (!Schema::hasColumn('works', 'explicit_text')) {
                $table->text('explicit_text')->nullable()->after('incipit_text');
            }
            if (!Schema::hasColumn('works', 'description')) {
                $table->text('description')->nullable()->after('explicit_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn(['incipit_text', 'explicit_text', 'description']);
        });
    }
};
