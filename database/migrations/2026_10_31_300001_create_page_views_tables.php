<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visit stats (backlog item 103, see App\Support\PageViews): the single views of the last 30 days, and the daily
 * counts they are folded into. No IP and no account is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            // The readers' day, Tehran time
            $table->date('day');
            // manuscript, work, person, library, source_page, source_item, source, cataloger, subject, post, search, page
            $table->string('type', 20);
            // The record's id, a source page's «code/volume/page», the search tab, or another page's route name
            $table->string('ref', 120);
            // Hash of IP and user agent with the day's salt, which is deleted the next day
            $table->char('visitor', 16);
            // Domain only; «self» for a click inside the site, null for none
            $table->string('referrer', 100)->nullable();
            $table->boolean('is_mobile')->default(false);
            $table->boolean('is_member')->default(false);

            $table->index(['day', 'type', 'ref']);
            $table->index(['type', 'ref']);
        });

        Schema::create('page_view_daily', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            // The types of page_views, and the site's breakdowns: _site, _device, _member, _referrer
            $table->string('type', 20);
            $table->string('ref', 120);
            $table->unsignedInteger('views');
            // Unique visitors of that day
            $table->unsignedInteger('visitors');

            $table->unique(['day', 'type', 'ref']);
            $table->index(['type', 'day']);
            $table->index(['type', 'ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_view_daily');
        Schema::dropIfExists('page_views');
    }
};
