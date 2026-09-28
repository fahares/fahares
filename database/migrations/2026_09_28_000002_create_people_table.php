<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('name', 600)->index();
            $table->string('normalized_name', 600)->index();
            $table->string('slug')->unique();
            $table->smallInteger('birth_year_hijri')->nullable()->index();
            $table->smallInteger('death_year_hijri')->nullable()->index();
            $table->tinyInteger('century_hijri')->nullable()->index();
            $table->smallInteger('death_year_gregorian')->nullable();
            $table->text('transliteration')->nullable();
            $table->text('bio_notes')->nullable();
            $table->boolean('is_author')->default(false)->index();
            $table->boolean('is_scribe')->default(false)->index();
            $table->boolean('is_translator')->default(false)->index();
            $table->boolean('is_donor')->default(false)->index();
            $table->unsignedInteger('works_count')->default(0);
            $table->unsignedInteger('manuscripts_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
