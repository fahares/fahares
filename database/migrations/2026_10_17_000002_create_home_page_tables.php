<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content of the home page that the admin edits: banner slides and the «از گنجینه‌ها» images
     * (home_items), short texts (site_settings), and which catalogers appear on the home page and
     * in what order (catalogers.home_order; null keeps a cataloger off the home page).
     */
    public function up(): void
    {
        Schema::create('home_items', function (Blueprint $table) {
            $table->id();
            $table->string('section', 20)->index();
            $table->string('title')->nullable();
            $table->text('subtitle')->nullable();
            $table->string('credit')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('image_path');
            $table->boolean('show_caption')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('catalogers', function (Blueprint $table) {
            $table->unsignedSmallInteger('home_order')->nullable()->index()->after('volumes_count');
        });
    }

    public function down(): void
    {
        Schema::table('catalogers', function (Blueprint $table) {
            $table->dropColumn('home_order');
        });
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('home_items');
    }
};
