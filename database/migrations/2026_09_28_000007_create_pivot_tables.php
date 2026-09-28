<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. subject_work
        Schema::create('subject_work', function (Blueprint $table) {
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->primary(['subject_id', 'work_id']);
        });

        // 2. language_work
        Schema::create('language_work', function (Blueprint $table) {
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->primary(['language_id', 'work_id']);
        });

        // 3. manuscript_script
        Schema::create('manuscript_script', function (Blueprint $table) {
            $table->foreignId('manuscript_id')->constrained('manuscripts')->cascadeOnDelete();
            $table->foreignId('script_id')->constrained('scripts')->cascadeOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->primary(['manuscript_id', 'script_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manuscript_script');
        Schema::dropIfExists('language_work');
        Schema::dropIfExists('subject_work');
    }
};
