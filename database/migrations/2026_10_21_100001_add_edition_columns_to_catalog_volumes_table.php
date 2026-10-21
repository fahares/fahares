<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A later printing of a library catalogue volume (backlog item 51) is a volume row of its own with no
     * catalog_id: edition_of_id names the volume Fankha cites, and facsimile_code the folder of its page
     * images under storage/app/public/catalogs. source_url (where the scan came from) stays in the
     * database and is never shown on the site or in any public output.
     */
    public function up(): void
    {
        Schema::table('catalog_volumes', function (Blueprint $table) {
            $table->foreignId('edition_of_id')->nullable()->after('catalog_id')->constrained('catalog_volumes')->nullOnDelete();
            $table->string('edition_label', 255)->nullable()->after('edition_of_id');
            $table->string('facsimile_code', 60)->nullable()->after('edition_label')->index();
            $table->unsignedInteger('page_first')->nullable()->after('pages_count');
            $table->unsignedInteger('page_last')->nullable()->after('page_first');
            $table->string('source_url', 500)->nullable()->after('mtif_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_volumes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edition_of_id');
            $table->dropIndex(['facsimile_code']);
            $table->dropColumn(['edition_label', 'facsimile_code', 'page_first', 'page_last', 'source_url']);
        });
    }
};
