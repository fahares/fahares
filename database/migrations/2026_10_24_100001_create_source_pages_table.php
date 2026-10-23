<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The text of a source's pages (step 13.6), so the site can take an entry's text again at a corrected address, and
     * a reviewer can read the pages around it. sources.extractor names the way an entry is found in those pages: each
     * printed format has its own (App\Sources\Extractors), and a source without one is never read automatically.
     */
    public function up(): void
    {
        Schema::create('source_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_volume_id')->constrained('source_volumes')->cascadeOnDelete();
            // the printed page (or column) number
            $table->unsignedInteger('page');
            $table->longText('text');
            $table->timestamps();
            $table->unique(['source_volume_id', 'page']);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->string('extractor', 40)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('extractor');
        });
        Schema::dropIfExists('source_pages');
    }
};
