<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The «مجموعه» as a record of its own (backlog item 163, step 14.322; docs/plans/collections.md): the bound volume
 * the treatises of one library with one collection_key are in. Its value columns bear the names of the copies'
 * columns and stay empty until the step that derives them; a copy's own columns are never written from here.
 * manuscripts.collection_id follows collection_key (App\Support\Collections) unless collection_pinned says an editor
 * placed the copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->string('key', 100);
            // bound: treatises in one binding; set: the volumes of one work under one number
            $table->string('kind', 10)->default('bound');
            $table->text('binding')->nullable();
            $table->string('dimensions')->nullable();
            $table->unsignedSmallInteger('folios')->nullable();
            $table->text('paper')->nullable();
            $table->text('script_names')->nullable();
            $table->text('script_style')->nullable();
            $table->unsignedSmallInteger('lines')->nullable();
            $table->string('lines_raw')->nullable();
            $table->foreignId('scribe_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('scribe_name', 600)->nullable();
            $table->text('copy_date_raw')->nullable();
            $table->text('copy_place')->nullable();
            // where each filled value column comes from: agreement, single, catalogue or editor
            $table->json('basis')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('copies_count')->default(0);
            $table->timestamps();
            $table->unique(['library_id', 'key']);
        });

        Schema::table('manuscripts', function (Blueprint $table) {
            $table->foreignId('collection_id')->nullable()->after('collection_key')->constrained('collections')->nullOnDelete();
            $table->boolean('collection_pinned')->default(false)->after('collection_id');
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_id');
            $table->dropColumn('collection_pinned');
        });
        Schema::dropIfExists('collections');
    }
};
