<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The technical alerts and access notices reach the admins at once only through the alerts chat, and each admin
 * gathers them into the daily digest: the choices stored for their per-event channels (email, browser, personal
 * Telegram) are dropped, so the new defaults hold. An admin may switch them on again on the settings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_preferences')
            ->whereIn('notification_type', ['system.queue_failed', 'system.search_failed', 'system.command_finished', 'user.registered', 'permissions.changed'])
            ->whereIn('channel', ['mail', 'webpush', 'telegram', 'digest'])
            ->delete();
    }

    public function down(): void
    {
        // The dropped choices are not kept
    }
};
