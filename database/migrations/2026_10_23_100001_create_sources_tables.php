<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One registry of sources and of where each record stands in them (step 13.1). A source is any printed work a
     * record is described in: Fankha (a union catalogue compiled from the others, so shown last), a library's own
     * catalogue, a bibliography (al-Dhari'a) or a biographical dictionary (al-A'lam, Tabaqat). Every source has its
     * volumes, and source_entries says "this work, manuscript or person is at this place of this volume", with the
     * text printed there. field_evidence holds the grounds of a correction: an entry of a registered source, a work
     * the site does not hold (an article, a book) or the researcher's own reasoning, as many as the change needs.
     *
     * The tables are additive: catalogs, catalog_volumes, source_citations, manuscript_catalog_references, the
     * raw_text of works and manuscripts and works.bibliography stay the site's data until the code reads from here.
     * sources:backfill copies them and can be run again; migrated rows keep their ids (sources.id = catalogs.id,
     * source_volumes.id = catalog_volumes.id), and rows made here start far above them so later volumes and catalogs
     * can still be copied with their own ids.
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->nullable()->unique();
            // union_catalogue | library_catalogue | bibliography | biographical | other
            $table->string('kind', 30)->index();
            // display order of a record's sources, lowest first; Fankha, the secondary one, comes last
            $table->unsignedSmallInteger('rank')->default(50);
            $table->string('title', 500);
            $table->string('short_name', 150)->nullable();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->nullOnDelete();
            $table->foreignId('edition_of_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->string('edition_label', 255)->nullable();
            $table->string('compiler', 255)->nullable();
            $table->string('publisher', 255)->nullable();
            $table->string('publication_year', 50)->nullable();
            $table->unsignedSmallInteger('volumes_count')->nullable();
            // صفحه, or ستون for the books numbered by column
            $table->string('unit', 20)->default('صفحه');
            $table->text('description')->nullable();
            $table->text('citation_format')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('source_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->unsignedSmallInteger('volume_number')->nullable();
            $table->string('title', 500)->nullable();
            $table->foreignId('edition_of_id')->nullable()->constrained('source_volumes')->nullOnDelete();
            // the printing as its own volume names it; the volumes of one printing may differ («۱۳۸۸، به کوشش …»)
            $table->string('edition_label', 255)->nullable();
            $table->string('facsimile_code', 60)->nullable()->index();
            $table->string('cover_image_path', 500)->nullable();
            $table->string('publisher', 255)->nullable();
            $table->string('publication_year', 50)->nullable();
            $table->unsignedInteger('pages_count')->nullable();
            $table->unsignedInteger('page_first')->nullable();
            $table->unsignedInteger('page_last')->nullable();
            $table->string('manuscripts_range', 100)->nullable();
            $table->string('citation_pattern', 100)->nullable()->index();
            $table->unsignedBigInteger('mtif_entry_id')->nullable()->index();
            // where the scan came from: kept in the database, never shown on the site
            $table->string('source_url', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['source_id', 'volume_number']);
        });

        Schema::create('source_entries', function (Blueprint $table) {
            $table->id();
            $table->string('citable_type', 100);
            $table->unsignedBigInteger('citable_id');
            // null: a source the site does not hold yet, known only by citation_text
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->foreignId('source_volume_id')->nullable()->constrained('source_volumes')->nullOnDelete();
            $table->unsignedSmallInteger('volume_number')->nullable();
            $table->unsignedInteger('page_start')->nullable();
            $table->unsignedInteger('page_end')->nullable();
            $table->unsignedInteger('entry_number')->nullable();
            $table->unsignedInteger('shelfmark_page')->nullable();
            // entry (مدخل) | addendum (استدراک) | erratum (اصلاحیه) | citation (یادکرد در مآخذ)
            $table->string('role', 20)->default('entry');
            // the entry the record was made from
            $table->boolean('is_primary')->default(false);
            // the place as the citing text writes it: «[ف: 30-576]», «الذریعه 101/25»
            $table->text('citation_text')->nullable();
            // where the extra places of one citation_text go: [{source_id, volume, page}]
            $table->json('locations')->nullable();
            // the text printed there: the entry (shared by its copies) and the copy's own line
            $table->longText('entry_text')->nullable();
            $table->text('copy_text')->nullable();
            // the place the text was taken from; when it is not the entry's place the text is stale
            $table->unsignedSmallInteger('text_volume_number')->nullable();
            $table->unsignedInteger('text_page')->nullable();
            // none | auto | edited | stale
            $table->string('text_status', 20)->default('none');
            // fankha | fankha_citation | bibliography | match | manual
            $table->string('origin', 30)->index();
            $table->string('match_method', 40)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('status', 20)->default('unverified')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            // the row sources:backfill copied it from, so a second run updates instead of adding
            $table->string('legacy_type', 40)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->unsignedSmallInteger('legacy_index')->default(0);
            $table->timestamps();

            $table->index(['citable_type', 'citable_id'], 'source_entries_citable');
            $table->index(['source_id', 'volume_number', 'page_start'], 'source_entries_place');
            $table->index(['source_volume_id', 'status'], 'source_entries_volume_status');
            $table->unique(['legacy_type', 'legacy_id', 'legacy_index'], 'source_entries_legacy');
        });

        Schema::create('field_evidence', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->string('field_name', 100)->nullable();
            $table->foreignId('field_suggestion_id')->nullable()->constrained('field_suggestions')->cascadeOnDelete();
            $table->foreignId('audit_log_id')->nullable()->constrained('audit_logs')->nullOnDelete();
            // source_entry (an entry on the site) | source (a registered source, at a place it has no entry for)
            // | external (a work the site does not hold) | research (the researcher's own reasoning)
            $table->string('kind', 20);
            $table->foreignId('source_entry_id')->nullable()->constrained('source_entries')->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->unsignedSmallInteger('volume_number')->nullable();
            $table->unsignedInteger('page')->nullable();
            // the work as the contributor cites it: author, title, journal, year, page
            $table->text('citation')->nullable();
            $table->string('url', 1000)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('legacy_type', 40)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'field_name'], 'field_evidence_subject');
            $table->unique(['legacy_type', 'legacy_id'], 'field_evidence_legacy');
        });

        // rows made here start far above the copied ones (see above)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE sources AUTO_INCREMENT = 100000');
            DB::statement('ALTER TABLE source_volumes AUTO_INCREMENT = 1000000');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_evidence');
        Schema::dropIfExists('source_entries');
        Schema::dropIfExists('source_volumes');
        Schema::dropIfExists('sources');
    }
};
