<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a notice was put into the members' notifications (type site.announcement): once, at its first run.
     * Notices that ran before this step are marked as told, so that saving one of them again sends nothing.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('telegram_error');
        });
        DB::table('announcements')->whereNotNull('starts_at')->update(['notified_at' => DB::raw('starts_at')]);
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
