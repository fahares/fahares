<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The date conflict a suggestion answers (step 14.169): a note beside a conflicting date on a public page opens the
 * suggestion form, and the reviewer sees the answers beside the conflict. The tie is dropped when the conflict goes
 * (the dates were mended), and the suggestion stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->foreignId('date_conflict_id')->nullable()->constrained('date_conflicts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('date_conflict_id');
        });
    }
};
