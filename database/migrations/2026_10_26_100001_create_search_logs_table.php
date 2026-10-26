<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The site's searches, for the admin page «آمار جستجو» (backlog item 83, phase 0). Anonymous on purpose:
 * no user id, IP or session, only whether the searcher was signed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            // Normalized, as the engine received it; empty for browsing by filters alone
            $table->string('query', 200)->default('');
            $table->string('type', 16);
            $table->string('scope', 16);
            $table->json('filters')->nullable();
            $table->unsignedInteger('results_count');
            $table->boolean('is_member')->default(false);
            // The rank of the first result opened from the page, counted from 1
            $table->unsignedSmallInteger('clicked_position')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['query', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
