<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An entry of a work's «وابسته به» or «شروح و حواشی» text tied to the work it names, where the title and author
     * alone do not tell (backlog item 6, step 14.270): the work's page links the entry by this row. «entry» is the
     * entry as WorkRelationLinker::entryKey writes it; «side» is «related» or «children».
     */
    public function up(): void
    {
        Schema::create('work_entry_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->string('side', 10);
            $table->text('entry');
            $table->char('entry_hash', 40);
            $table->foreignId('linked_work_id')->constrained('works')->cascadeOnDelete();
            $table->string('source', 20)->default('model');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->timestamps();
            $table->unique(['work_id', 'side', 'entry_hash']);
            $table->index('linked_work_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_entry_links');
    }
};
