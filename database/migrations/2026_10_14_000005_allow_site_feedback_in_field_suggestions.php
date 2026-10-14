<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            // Site feedback (kind "feedback") is about a page, not a record; its page is in payload
            $table->string('suggestable_type', 100)->nullable()->change();
            $table->unsignedBigInteger('suggestable_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('field_suggestions')->whereNull('suggestable_type')->delete();
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->string('suggestable_type', 100)->nullable(false)->change();
            $table->unsignedBigInteger('suggestable_id')->nullable(false)->change();
        });
    }
};
