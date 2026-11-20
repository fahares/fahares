<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two texts of related works are rows of work_text_relations (WorkCatalogueEntries, backlog item 6, step
     * 14.285), so the fields they were, works.related_work and works.commentaries_and_glosses, go; and with them
     * work_entry_links, whose ties and reviewer's queue are columns of the same rows.
     *
     * In production the fields are dropped only once works:materialize-related (step 14.283) has written their
     * entries: the migration stops, and the deploy with it, if the works that have a text are not there as rows.
     */
    public function up(): void
    {
        if (app()->environment('production') && Schema::hasColumn('works', 'related_work')) {
            $withText = DB::table('works')->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('related_work')->where('related_work', '<>', ''))
                ->orWhere(fn ($q) => $q->whereNotNull('commentaries_and_glosses')->whereNotIn('commentaries_and_glosses', ['', '[]'])))->count();
            $written = DB::table('work_text_relations')->where('source', 'catalogue')->distinct()->count('work_id');
            if ($written < $withText) {
                throw new RuntimeException("works.related_work is not dropped: {$withText} works have a text of related works and only {$written} have rows in "
                    . 'work_text_relations. Run works:materialize-related (step 14.283) on the release before this one first.');
            }
        }
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn(['related_work', 'commentaries_and_glosses']);
        });
        Schema::dropIfExists('work_entry_links');
    }

    public function down(): void
    {
        // the columns come back empty: their texts are the rows of work_text_relations now
        Schema::table('works', function (Blueprint $table) {
            $table->text('related_work')->nullable();
            $table->json('commentaries_and_glosses')->nullable();
        });
    }
};
