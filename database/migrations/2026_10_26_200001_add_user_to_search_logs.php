<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signed-in user's searches are kept with their account, unless they turn it off in «حساب من»
 * (backlog item 83, user decision of 4 Aban 1405). Guests stay anonymous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_logs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('results_count')->constrained()->nullOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('search_history')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('search_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('search_history');
        });
    }
};
