<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            // Index for fast fetching of manuscripts by work and their century ranges
            $table->index(['work_id', 'copy_date_hijri_year'], 'manuscripts_work_id_idx');
            // Index for fast check of autograph manuscripts by work
            $table->index(['work_id', 'is_autograph'], 'manuscripts_work_autograph_idx');
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropIndex('manuscripts_work_id_idx');
            $table->dropIndex('manuscripts_work_autograph_idx');
        });
    }
};
