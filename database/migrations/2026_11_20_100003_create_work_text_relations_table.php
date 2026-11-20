<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A work's relation with a work the site has no record of (backlog item 6, step 14.280): the other work is its
     * title and author as text. «side» is «related» (this work is a {type} of the named one) or «children» (the
     * named one is a {type} of this work), as in work_entry_links. Rows come from approved suggestions.
     */
    public function up(): void
    {
        Schema::create('work_text_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->string('side', 10);
            $table->string('type', 30);
            $table->string('text', 600);
            $table->string('note', 500)->nullable();
            $table->foreignId('suggestion_id')->nullable()->constrained('field_suggestions')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_text_relations');
    }
};
