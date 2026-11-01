<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 13.129 (plan docs/plans/dharia-entity-links.md). The records a mark in a page's text links, made by a command
     * beforehand, not as the page is shown: the page, which of its marks (its n-th opening, from 0) with the mark's kind
     * and text as they were, the record it links (target), how the link was found (method), and an editor's rejection,
     * which a later run keeps.
     */
    public function up(): void
    {
        Schema::create('source_page_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_page_id')->constrained('source_pages')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordinal');
            $table->string('kind', 20);
            $table->string('text', 500);
            $table->morphs('target');
            $table->string('method', 30);
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_page_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_page_links');
    }
};
