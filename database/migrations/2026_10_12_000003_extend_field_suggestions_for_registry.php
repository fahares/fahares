<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            // field: a correction to one registered field; general: free-text report reviewed by hand
            $table->string('kind', 20)->default('field')->after('suggestable_id')->index();
            $table->string('field_name', 100)->nullable()->change();
            $table->text('rationale_citation')->nullable()->change();
            // Structured extras from the submitter, e.g. {"person_id": 12} for a scribe
            $table->json('payload')->nullable()->after('rationale_citation');
            // What the reviewer actually applied, e.g. {"person_action": "link", "person_id": 12}
            $table->json('resolution')->nullable()->after('admin_notes');
        });
    }

    public function down(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->dropColumn(['kind', 'payload', 'resolution']);
        });
    }
};
