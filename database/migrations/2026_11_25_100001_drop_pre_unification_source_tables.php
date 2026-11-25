<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the source tables of before the one registry of step 13.1 (step 14.382, backlog item 69). Since step 13.3
 * every read and write is on source_entries, which holds a copy of each row (legacy_type catalog_reference and
 * source_citation); the three tables stayed only as a way back to the code of before step 13.2.
 *
 * - manuscript_catalog_references: the copies' catalogue places, now entries of origin fankha_citation, match and manual.
 * - source_citations: addenda and errata, now entries of role addendum and erratum.
 * - field_evidence: 13 rationales copied from suggestions; the suggestion's own text field stays their record.
 *
 * Production's rows were exported before the drop to fahares-sources/backups/step13/. There is no way down: the
 * tables come back only from that export.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('field_evidence');
        Schema::dropIfExists('source_citations');
        Schema::dropIfExists('manuscript_catalog_references');
    }

    public function down(): void
    {
        throw new \RuntimeException('The pre-unification source tables come back only from the export in fahares-sources/backups/step13/.');
    }
};
