<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a manuscript is described in a library catalogue (backlog item 51), one row per reference:
     * the Fankha citation moved from metadata.catalog_citation (source fankha), a page found by matching
     * the shelfmark in another printing (ai_match), or one entered by hand (manual). The page viewer opens
     * page_start, where the entry begins; shelfmark_page is where the copy's number was found.
     */
    public function up(): void
    {
        Schema::create('manuscript_catalog_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manuscript_id')->constrained('manuscripts')->cascadeOnDelete();
            $table->foreignId('catalog_volume_id')->nullable()->constrained('catalog_volumes')->nullOnDelete();
            $table->unsignedSmallInteger('volume_number')->nullable();
            $table->unsignedInteger('page_start')->nullable();
            $table->unsignedInteger('page_end')->nullable();
            $table->unsignedInteger('shelfmark_page')->nullable();
            $table->unsignedInteger('entry_number')->nullable();
            $table->text('raw_text')->nullable();
            $table->string('source', 20)->index();
            $table->string('match_method', 40)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('status', 20)->default('unverified')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['manuscript_id', 'catalog_volume_id', 'page_start', 'source'], 'manuscript_catalog_references_unique');
            $table->index(['catalog_volume_id', 'source', 'status'], 'manuscript_catalog_references_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manuscript_catalog_references');
    }
};
