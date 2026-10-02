<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->foreignId('cataloger_id')->nullable()->after('catalog_id')->constrained('catalogers')->nullOnDelete();
            $table->foreignId('catalog_volume_id')->nullable()->after('cataloger_id')->constrained('catalog_volumes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropForeign(['catalog_volume_id']);
            $table->dropColumn('catalog_volume_id');
            $table->dropForeign(['cataloger_id']);
            $table->dropColumn('cataloger_id');
        });
    }
};
