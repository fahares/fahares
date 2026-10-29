<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People connected with a work other than its authors (work_author): translators, the author of the
     * original, dedicatees, commissioners, its subject, editors… Each row is a linked person or only a name
     * (e.g. «علی», which identifies no one), with the role, how sure the catalogue is (certainty) and the
     * cataloguer's own hedge word (hedge_raw, e.g. «ظاهراً» or «؟»). source_entry_id is the catalogue entry
     * the row was read from; suggestion_id the reviewed suggestion that added it.
     * Step 1 of the hybrid data-structure roadmap (backlog item 35); work_author stays as it is for now.
     */
    public function up(): void
    {
        Schema::create('work_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('name', 600)->nullable();
            $table->string('role', 30)->index();
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('certainty', 10)->nullable();
            $table->string('hedge_raw', 60)->nullable();
            $table->foreignId('source_entry_id')->nullable()->constrained('source_entries')->nullOnDelete();
            $table->foreignId('suggestion_id')->nullable()->constrained('field_suggestions')->nullOnDelete();
            $table->timestamps();
            $table->index(['work_id', 'role']);
            $table->index(['person_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_people');
    }
};
