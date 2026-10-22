<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The text of the printed catalogue at a reference (backlog item 60): entry_text is the entry (heading, author,
     * description, incipit and explicit) shared by every copy of it, in a composite volume the treatise only; copy_text
     * is the copy's own line, «ش 2366، 162 برگ، …», or the composite volume's heading and closing notes.
     */
    public function up(): void
    {
        Schema::table('manuscript_catalog_references', function (Blueprint $table) {
            $table->text('entry_text')->nullable()->after('raw_text');
            $table->text('copy_text')->nullable()->after('entry_text');
        });
    }

    public function down(): void
    {
        Schema::table('manuscript_catalog_references', function (Blueprint $table) {
            $table->dropColumn(['entry_text', 'copy_text']);
        });
    }
};
