<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The bound volume a treatise belongs to (backlog item 97): «3852» for «3852/4», «1068-طباطبائی» for
     * «1068/2-طباطبائی» (Manuscript::collectionKeyOf). Copies of one library with one key are bound together. Derived
     * from the shelfmark; fahares:fill-collection-keys fills it and DerivedData keeps it in step.
     */
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->string('collection_key', 100)->nullable()->after('shelfmark_key');
            $table->index(['library_id', 'collection_key']);
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropIndex(['library_id', 'collection_key']);
            $table->dropColumn('collection_key');
        });
    }
};
