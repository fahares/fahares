<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Subjects (موضوعات)
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('slug', 255)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->text('description')->nullable();
            $table->unsignedInteger('works_count')->default(0);
            $table->timestamps();
        });

        // 2. Languages (زبان‌ها)
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('code', 10)->nullable();
            $table->string('slug', 255)->unique();
            $table->unsignedInteger('works_count')->default(0);
            $table->timestamps();
        });

        // 3. Scripts (انواع خطوط)
        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('slug', 255)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('manuscripts_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scripts');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('subjects');
    }
};
