<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How one source is cited in another (step 14.57, docs/plans/source-name-patterns.md): the names a citing source
 * writes for a cited one and how it reads its numbers. No citing source is the form any source uses, which one of a
 * citing source's own overrides. The patterns of config/bibliography.php come here (sources:import-citation-forms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_citation_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cited_source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignId('citing_source_id')->nullable()->constrained('sources')->cascadeOnDelete();
            // where in the citing source: a work's bibliography strings, the text of its pages
            $table->string('context', 20)->default('bibliography');
            $table->json('names')->nullable();
            $table->text('regex')->nullable();
            // how the numbers are read: volumes, series_volumes, pairs_after_name, other_edition, unit
            $table->json('options')->nullable();
            $table->text('notes')->nullable();
            $table->json('examples')->nullable();
            $table->timestamps();
            $table->index(['context', 'citing_source_id']);
            $table->index('cited_source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_citation_forms');
    }
};
