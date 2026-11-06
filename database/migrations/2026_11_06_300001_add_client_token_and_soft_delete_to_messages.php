<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backlog item 115: a letter sent twice on a flaky connection is written once. Each send carries a random
 * client_token (an email reply its Resend id), unique here, so a repeated request finds the first letter instead
 * of writing a second one. An admin can soft-delete a letter that still came twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('client_token', 80)->nullable()->unique()->after('via');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['client_token']);
            $table->dropColumn(['client_token', 'deleted_at']);
        });
    }
};
