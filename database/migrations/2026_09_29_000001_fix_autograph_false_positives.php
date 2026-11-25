<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A data fix for the imported records, made by fahares:fix-autograph-manuscripts when this migration ran.
        // The command went with the other one-off fixes in step 14.392 (backlog item 170); a fresh database has
        // nothing to fix.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No automatic rollback since original state had inaccurate false positives
    }
};
