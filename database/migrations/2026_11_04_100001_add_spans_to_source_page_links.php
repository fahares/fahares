<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 13.155 (plan docs/plans/page-text-links.md). A link may cover words of the page that are no Noor mark: a
     * shelfmark after a library's mark, linked to the copy, or a reference to a volume and page of the source itself
     * («(161:7)»). offset is where those words start in the page's text (in characters) and span the words as they
     * were, so a page whose text changed shows no link there. ordinal is null for a link of such words alone, and a
     * mark may link more than one record (a library and its copy): one link of each type a mark.
     */
    public function up(): void
    {
        Schema::table('source_page_links', function (Blueprint $table) {
            $table->unsignedInteger('offset')->nullable()->after('ordinal');
            $table->string('span', 255)->nullable()->after('offset');
            $table->index('source_page_id');
        });
        Schema::table('source_page_links', function (Blueprint $table) {
            $table->dropUnique(['source_page_id', 'ordinal']);
            $table->unsignedSmallInteger('ordinal')->nullable()->change();
            $table->unique(['source_page_id', 'ordinal', 'target_type']);
        });
    }

    public function down(): void
    {
        Schema::table('source_page_links', function (Blueprint $table) {
            $table->dropUnique(['source_page_id', 'ordinal', 'target_type']);
        });
        \Illuminate\Support\Facades\DB::table('source_page_links')->whereNull('ordinal')->orWhere('target_type', 'App\Models\Manuscript')->delete();
        Schema::table('source_page_links', function (Blueprint $table) {
            $table->unsignedSmallInteger('ordinal')->nullable(false)->change();
            $table->unique(['source_page_id', 'ordinal']);
            $table->dropIndex(['source_page_id']);
            $table->dropColumn(['offset', 'span']);
        });
    }
};
