<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('catalogers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('title_prefix', 100)->nullable(); // استاد، دکتر، علامه، آیت‌الله
            $table->string('nickname', 150)->nullable(); // شهرت: حائری، دانش‌پژوه، منزوی
            $table->text('bio')->nullable();
            $table->smallInteger('birth_year_solar')->nullable();
            $table->smallInteger('death_year_solar')->nullable();
            $table->smallInteger('birth_year_hijri')->nullable();
            $table->smallInteger('death_year_hijri')->nullable();
            $table->boolean('is_alive')->default(false);
            $table->string('avatar_path', 500)->nullable();
            $table->unsignedBigInteger('mtif_entry_id')->nullable()->index();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('manuscripts_count')->default(0);
            $table->unsignedInteger('volumes_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogers');
    }
};
