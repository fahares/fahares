<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A manuscript may have several scribes and a work several authors.
     * works.author_id and manuscripts.scribe_id stay as the primary (first) person;
     * the pivots hold every linked person, the primary one included at position 0.
     * The *_name columns keep the name as printed in the catalog.
     */
    public function up(): void
    {
        Schema::create('manuscript_scribe', function (Blueprint $table) {
            $table->foreignId('manuscript_id')->constrained('manuscripts')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            // Share of the copy, as the catalog states it, e.g. «جلد اول» or «بخش تصدیقات»
            $table->string('note', 500)->nullable();
            $table->primary(['manuscript_id', 'person_id']);
            $table->index('person_id');
        });

        Schema::create('work_author', function (Blueprint $table) {
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('note', 500)->nullable();
            $table->primary(['work_id', 'person_id']);
            $table->index('person_id');
        });

        // «و دیگران», «و غیره», «و جز او»: unnamed further contributors
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->boolean('has_other_scribes')->default(false)->after('scribe_name');
        });
        Schema::table('works', function (Blueprint $table) {
            $table->boolean('has_other_authors')->default(false)->after('author_name');
        });

        DB::statement('INSERT INTO manuscript_scribe (manuscript_id, person_id, position)
            SELECT id, scribe_id, 0 FROM manuscripts WHERE scribe_id IS NOT NULL');
        DB::statement('INSERT INTO work_author (work_id, person_id, position)
            SELECT id, author_id, 0 FROM works WHERE author_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('works', fn(Blueprint $table) => $table->dropColumn('has_other_authors'));
        Schema::table('manuscripts', fn(Blueprint $table) => $table->dropColumn('has_other_scribes'));
        Schema::dropIfExists('work_author');
        Schema::dropIfExists('manuscript_scribe');
    }
};
