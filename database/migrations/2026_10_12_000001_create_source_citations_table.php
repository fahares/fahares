<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additional places a work or manuscript is described in a printed catalog.
     * The primary entry stays on the record itself (catalog_id, volume_number, page_start, page_end);
     * this table holds every other citation: addenda, errata, other catalogs.
     */
    public function up(): void
    {
        Schema::create('source_citations', function (Blueprint $table) {
            $table->id();
            $table->string('citable_type', 100);
            $table->unsignedBigInteger('citable_id');
            $table->foreignId('catalog_id')->constrained('catalogs')->cascadeOnDelete();
            $table->unsignedSmallInteger('volume_number')->nullable();
            $table->unsignedInteger('page_start')->nullable();
            $table->unsignedInteger('page_end')->nullable();
            // addendum (استدراک) | erratum (اصلاحیه) | entry (مدخل در مأخذی دیگر)
            $table->string('role', 30)->default('entry');
            $table->string('label')->nullable();
            $table->longText('raw_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['citable_type', 'citable_id'], 'idx_citable');
            $table->index(['catalog_id', 'volume_number', 'page_start'], 'idx_catalog_page');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_citations');
    }
};
