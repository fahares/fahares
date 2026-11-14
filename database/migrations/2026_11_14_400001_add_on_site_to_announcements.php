<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A notice with on_site off is written only for the Telegram channel: it is not shown on the site, is
     * not put into the members' notifications, and takes no place in the visitors' queue of notices.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('on_site')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('on_site');
        });
    }
};
