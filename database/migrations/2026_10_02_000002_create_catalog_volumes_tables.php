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
        Schema::create('catalog_volumes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 500);
            $table->unsignedSmallInteger('volume_number')->nullable();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->nullOnDelete();
            $table->foreignId('catalog_id')->nullable()->constrained('catalogs')->nullOnDelete();
            $table->string('cover_image_path', 500)->nullable();
            $table->string('publisher', 255)->nullable();
            $table->string('publication_year', 50)->nullable();
            $table->unsignedInteger('pages_count')->nullable();
            $table->string('manuscripts_range', 100)->nullable();
            $table->string('citation_pattern', 100)->nullable()->index();
            $table->unsignedBigInteger('mtif_entry_id')->nullable()->index();
            $table->unsignedInteger('manuscripts_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_volume_cataloger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_volume_id')->constrained('catalog_volumes')->cascadeOnDelete();
            $table->foreignId('cataloger_id')->constrained('catalogers')->cascadeOnDelete();
            $table->string('role', 100)->default('cataloger'); // فهرست‌نگار، زیر نظر، همکار
            $table->timestamps();

            $table->unique(['catalog_volume_id', 'cataloger_id'], 'cv_cat_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_volume_cataloger');
        Schema::dropIfExists('catalog_volumes');
    }
};
