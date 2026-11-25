<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A copy's row in its work's Fankha entry (manuscripts.sequence_number) goes into its Fankha entry as entry_number
 * (step 14.396, backlog item 170): the entry is the record of truth for the copy's Fankha place, and the column
 * becomes a copy of it until the readers move to the entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE source_entries e JOIN manuscripts m ON m.id = e.legacy_id
            SET e.entry_number = m.sequence_number WHERE e.legacy_type = 'manuscript'");
    }

    public function down(): void
    {
        DB::table('source_entries')->where('legacy_type', 'manuscript')->update(['entry_number' => null]);
    }
};
