<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A copy's notes, one row a note, each with its type (backlog item 87, step 14.254): the pieces of a copy's
     * description that are no labelled field, typed by the approved list (ManuscriptNote::TYPES), and the labelled
     * seal, ownership, acquisition and endowment fields under their own type. Filled by fahares:import-copy-notes;
     * the metadata lists (editorial_notes, ownership_and_seals, residual_notes) stay as they are until the pages read
     * this table.
     */
    public function up(): void
    {
        Schema::create('manuscript_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manuscript_id')->constrained('manuscripts')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type', 20);
            $table->text('text');
            // the other types that fit the note
            $table->json('also')->nullable();
            $table->boolean('by_author')->default(false);
            $table->string('by_author_hedge', 60)->nullable();
            $table->string('hedge', 60)->nullable();
            // who gave the type: field (a labelled field of the description), rule, sonnet, opus, editor
            $table->string('typed_by', 10);
            $table->timestamps();

            $table->unique(['manuscript_id', 'position']);
            $table->index(['type', 'manuscript_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manuscript_notes');
    }
};
