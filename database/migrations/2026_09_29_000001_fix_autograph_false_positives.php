<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Data fix for imported records. The command now relies on tables created by later
        // migrations (manuscript_scribe), so it cannot run on an empty database (fresh install, test suite).
        if (! DB::table('manuscripts')->exists()) {
            return;
        }

        Artisan::call('fahares:fix-autograph-manuscripts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No automatic rollback since original state had inaccurate false positives
    }
};
