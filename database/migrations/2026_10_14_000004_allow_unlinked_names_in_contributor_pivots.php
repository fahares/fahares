<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scribe or author the catalog names but who cannot be identified («مهدی», «ابن محمد هاشم»)
     * is kept as a name only, without a person record: person_id is null and name holds the text.
     */
    public function up(): void
    {
        foreach (['manuscript_scribe' => 'manuscript_id', 'work_author' => 'work_id'] as $table => $key) {
            Schema::table($table, function (Blueprint $t) use ($key) {
                // Index for the record's foreign key before its primary key goes
                $t->unique([$key, 'person_id'], "{$key}_person_unique");
            });
            Schema::table($table, function (Blueprint $t) {
                $t->dropPrimary();
            });
            Schema::table($table, function (Blueprint $t) {
                $t->id()->first();
                $t->unsignedBigInteger('person_id')->nullable()->change();
                $t->string('name', 600)->nullable()->after('person_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['manuscript_scribe' => 'manuscript_id', 'work_author' => 'work_id'] as $table => $key) {
            \Illuminate\Support\Facades\DB::table($table)->whereNull('person_id')->delete();
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['id', 'name']);
            });
            Schema::table($table, function (Blueprint $t) use ($key) {
                $t->unsignedBigInteger('person_id')->nullable(false)->change();
                $t->primary([$key, 'person_id']);
                $t->dropUnique("{$key}_person_unique");
            });
        }
    }
};
