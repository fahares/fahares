<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Writings the admin publishes on the site: a report of a scholarly meeting, an introduction
     * to a manuscript, and the like. The body is Markdown for now (body_format names it, so another
     * editor can come later); published_at null keeps a post a draft that only admins can open.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('byline')->nullable();
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('body_format', 20)->default('markdown');
            $table->string('cover_path')->nullable();
            $table->string('cover_caption')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
