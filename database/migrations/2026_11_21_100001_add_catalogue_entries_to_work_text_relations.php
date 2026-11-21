<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * work_text_relations becomes the home of every entry of a work's related works that is text (backlog item 6, step
     * 14.283), so that the fields works.related_work and works.commentaries_and_glosses can go: the catalogue's own
     * entries («source» catalogue, in their «position») beside the ones added by hand («suggestion»).
     *
     * An entry may be tied to a work of the site: «linked_work_id» with «link_status» linked, by the rules («auto»),
     * the judged decisions («model») or a reviewer («review»); «proposed» and «rejected» are a reviewer's queue, the
     * work only a candidate. «differs»: the catalogue's words are not the tied work's title and author, and are shown
     * beside it. «entry_key» is WorkRelationLinker::entryKey of the entry; «model_verdict» is what the judging of step
     * 14.229 said of an entry it tied to no work (unsure, none). A row of type «note» is what the field says after its
     * entries.
     */
    public function up(): void
    {
        Schema::table('work_text_relations', function (Blueprint $table) {
            $table->text('text')->change();
            $table->string('source', 12)->default('suggestion')->index();
            $table->unsignedSmallInteger('position')->nullable();
            $table->text('entry_key')->nullable();
            $table->foreignId('linked_work_id')->nullable()->constrained('works')->nullOnDelete();
            $table->string('link_source', 12)->nullable();
            $table->string('link_status', 12)->nullable()->index();
            $table->boolean('differs')->default(false);
            $table->string('why', 300)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('model_verdict', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_text_relations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_work_id');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['source', 'position', 'entry_key', 'link_source', 'link_status', 'differs', 'why', 'confidence', 'decided_at', 'model_verdict']);
            $table->string('text', 600)->change();
        });
    }
};
