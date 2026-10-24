<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The printed entries of a source, apart from the site's records (plan docs/plans/dharia-text.md): al-Dhari'a's
     * 53,000 numbered entries first, later Tabaqat, Kahhala and the like. A record is tied to an entry by a row of
     * source_entries (source_item_id), with the usual origin, status and confidence, so machine links go through the
     * same review as every other. source_item_refs holds an entry's references to other places of the same source
     * («12 : 204»), resolved to the entry printed there when it can be told.
     */
    public function up(): void
    {
        Schema::create('source_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignId('source_volume_id')->constrained('source_volumes')->cascadeOnDelete();
            $table->unsignedSmallInteger('volume_number');
            // stable within the volume across re-runs: «1442», «1345#2» for a number printed twice, «1442/1» for the
            // first unnumbered sub-entry after 1442, «p402/0» for text before the volume's first numbered entry
            $table->string('item_key', 40);
            $table->unsignedInteger('position');
            $table->unsignedInteger('entry_number')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('source_items')->nullOnDelete();
            // entry | sub_entry | addendum
            $table->string('kind', 20)->default('entry');
            $table->unsignedInteger('page_start');
            $table->unsignedInteger('page_end');
            $table->string('title', 500);
            $table->string('title_normalized', 500)->index();
            $table->string('author_text', 500)->nullable();
            $table->unsignedSmallInteger('author_death_year')->nullable();
            $table->longText('text');
            $table->text('footnotes')->nullable();
            // auto | checked | edited (edited text is never replaced by a re-run)
            $table->string('text_status', 20)->default('auto');
            // where the text came from (ablibrary, ocr, manual)
            $table->string('text_origin', 30)->nullable();
            // number anomalies, the number another printing shows, and the like
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['source_volume_id', 'item_key']);
            $table->index(['source_volume_id', 'position']);
            $table->index(['source_volume_id', 'page_start']);
            $table->index(['source_id', 'volume_number', 'entry_number']);
        });

        Schema::create('source_item_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_item_id')->constrained('source_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            // the reference as printed: «12 : 204», «ذ 13 : 249 - 252»
            $table->string('raw', 100);
            $table->unsignedSmallInteger('target_volume');
            $table->unsignedInteger('target_page');
            $table->unsignedInteger('target_page_end')->nullable();
            $table->foreignId('target_item_id')->nullable()->constrained('source_items')->nullOnDelete();
            $table->string('method', 30)->nullable();
            $table->string('status', 20)->default('unverified');
            $table->timestamps();
            $table->index(['target_volume', 'target_page']);
        });

        Schema::table('source_entries', function (Blueprint $table) {
            $table->foreignId('source_item_id')->nullable()->after('source_volume_id')->constrained('source_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('source_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_item_id');
        });
        Schema::dropIfExists('source_item_refs');
        Schema::dropIfExists('source_items');
    }
};
