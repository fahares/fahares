<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('author_name', 500)->nullable()->index();
            $table->string('primary_title')->index();
            $table->string('clean_title')->index();
            $table->string('slug')->unique();
            $table->json('alternative_titles')->nullable(); // [{"title": "...", "transliteration": "..."}]
            $table->text('transliteration')->nullable();
            $table->text('composition_date_raw')->nullable();
            $table->smallInteger('composition_year_hijri')->nullable()->index();
            $table->unsignedTinyInteger('volume_number')->index();
            $table->unsignedSmallInteger('page_start')->index();
            $table->unsignedSmallInteger('page_end')->nullable()->index();
            $table->string('work_form', 100)->nullable();
            $table->text('subject_summary')->nullable();
            $table->string('language_summary', 500)->nullable()->index();
            $table->unsignedInteger('manuscripts_count')->default(0)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('works');
    }
};
