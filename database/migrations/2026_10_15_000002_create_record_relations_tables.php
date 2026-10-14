<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Directed relations between works (a commentary on, a translation of…) and between
     * manuscripts (copied from, collated with…). Each row reads "{record} is a {type} of {related}".
     * source/confidence leave room for the automatic commentary matching of backlog item 6.
     */
    public function up(): void
    {
        Schema::create('work_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignId('related_work_id')->constrained('works')->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('note', 500)->nullable();
            $table->string('source', 20)->default('suggestion');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->foreignId('suggestion_id')->nullable()->constrained('field_suggestions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['work_id', 'related_work_id', 'type']);
            $table->index('related_work_id');
        });

        Schema::create('manuscript_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manuscript_id')->constrained('manuscripts')->cascadeOnDelete();
            $table->foreignId('related_manuscript_id')->constrained('manuscripts')->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('note', 500)->nullable();
            $table->foreignId('suggestion_id')->nullable()->constrained('field_suggestions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['manuscript_id', 'related_manuscript_id', 'type'], 'manuscript_relations_unique');
            $table->index('related_manuscript_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manuscript_relations');
        Schema::dropIfExists('work_relations');
    }
};
