<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public notices of the site («کانال تلگرام راه افتاد»، «فهرست فلان کتابخانه افزوده شد»): a short text with an
     * optional link and picture, shown once to each visitor as a card in the corner or, for the rare
     * important one, as a window. announcement_user keeps what a signed-in member has seen, so the
     * notice does not come back on their other devices; a guest's browser remembers it by itself.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('link_label', 60)->nullable();
            $table->string('image_path')->nullable();
            $table->string('style', 10)->default('card');
            $table->string('audience', 10)->default('all');
            $table->boolean('is_active')->default(false);
            // A run starts when the notice is switched on and lasts this many days
            $table->unsignedSmallInteger('days')->default(14);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'ends_at']);
        });

        Schema::create('announcement_user', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('seen_at')->nullable();
            $table->primary(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_user');
        Schema::dropIfExists('announcements');
    }
};
