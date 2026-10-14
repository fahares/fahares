<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People connected with a copy other than its scribes: owners, endowers, correctors, glossators,
     * patrons, illuminators… Each row is a linked person or only a name, with the role and a note
     * (e.g. «مهر بیضی، ۱۲۴۰ق»). Rows come from reviewed suggestions (suggestion_id).
     */
    public function up(): void
    {
        Schema::create('manuscript_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manuscript_id')->constrained('manuscripts')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('name', 600)->nullable();
            $table->string('role', 30)->index();
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('suggestion_id')->nullable()->constrained('field_suggestions')->nullOnDelete();
            $table->timestamps();
            $table->index(['person_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manuscript_people');
    }
};
