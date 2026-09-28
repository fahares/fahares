<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('libraries', function (Blueprint $table) {
            $table->id();
            $table->string('city', 255)->index();
            $table->string('name', 255)->index();
            $table->string('full_name')->nullable();
            $table->string('country', 100)->default('ایران')->index();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('manuscripts_count')->default(0);
            $table->timestamps();

            $table->unique(['city', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('libraries');
    }
};
