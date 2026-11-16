<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The periods of a person's life (backlog item 139, step 14.218): that they lived, studied, taught, travelled or
     * held an office somewhere, from one time to another. The date is a DateSpan under the prefix `period`, so «از
     * میان 1000 و 1010 تا میان 1020 و 1030» fits; the place keeps the words it was written in, with its city and its
     * country or region beside it, as a copy's place does.
     */
    public function up(): void
    {
        Schema::create('person_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('place', 150)->nullable();
            $table->string('city', 40)->nullable()->index();
            $table->string('region', 40)->nullable()->index();
            $table->dateSpan('period');
            $table->string('note', 500)->nullable();
            $table->string('source_text', 500)->nullable();
            $table->foreignId('source_entry_id')->nullable()->constrained('source_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_periods');
    }
};
