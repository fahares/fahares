<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users.phone: an optional contact number in E.164, never shown publicly (backlog item 74).
     * field_suggestions.held_credit_name: the name a submitter asked for while their email was unverified;
     * it moves to credit_name once the email is verified (backlog item 76).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('affiliation');
        });
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->string('held_credit_name', 150)->nullable()->after('credit_name');
        });
    }

    public function down(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->dropColumn('held_credit_name');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
