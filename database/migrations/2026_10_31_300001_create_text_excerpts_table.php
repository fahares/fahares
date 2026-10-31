<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5 of the hybrid data-structure roadmap (backlog item 98). A work's incipits and explicits beyond its main one:
     * numbered ones («آغاز 1: …», «آغاز 2: …», n with an optional label such as «دیباچه») and those of its parts
     * («آغاز جلد دوم: …», is_part with the part's label). A copy's «برابر 2» or «برابر جلد دوم» is a row of the copy
     * pointing at the work's excerpt (same_as_excerpt_id), with the catalogue's wording (same_as_raw).
     * works.incipit_text/explicit_text and manuscripts.incipit_text/explicit_text stay as they are.
     */
    public function up(): void
    {
        Schema::create('text_excerpts', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('kind', 10);
            $table->unsignedSmallInteger('n')->nullable();
            $table->string('label', 120)->nullable();
            $table->boolean('is_part')->default(false);
            $table->text('text')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('same_as_excerpt_id')->nullable()->constrained('text_excerpts')->nullOnDelete();
            $table->string('same_as_raw', 120)->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_excerpts');
    }
};
