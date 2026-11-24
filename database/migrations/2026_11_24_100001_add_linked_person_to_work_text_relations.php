<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A row of «وابسته به» that is only a name, with no title, is the author of the text the work is made on and no
     * work (step 14.337, backlog item 6): such a row has the type «author» and, where one person has that name, is
     * tied to the person.
     */
    public function up(): void
    {
        Schema::table('work_text_relations', function (Blueprint $table) {
            $table->foreignId('linked_person_id')->nullable()->after('linked_work_id')->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_text_relations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_person_id');
        });
    }
};
