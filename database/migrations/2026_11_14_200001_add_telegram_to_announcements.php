<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A notice may also go to the site's Telegram channel, once, when it is first switched on
     * (App\Support\AnnouncementTelegram). telegram_text, when filled, replaces the title and body there, so
     * the channel's post may differ from the site's (emoji, wording). The message id and the posted text
     * are kept so that a later edit changes the same post instead of sending a second one.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('telegram')->default(false)->after('audience');
            $table->text('telegram_text')->nullable()->after('telegram');
            $table->unsignedBigInteger('telegram_message_id')->nullable()->after('telegram_text');
            // A post sent with a picture is edited by its caption, one without by its text
            $table->boolean('telegram_photo')->default(false)->after('telegram_message_id');
            $table->text('telegram_posted_text')->nullable()->after('telegram_photo');
            $table->timestamp('telegram_sent_at')->nullable()->after('telegram_posted_text');
            $table->string('telegram_error', 500)->nullable()->after('telegram_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['telegram', 'telegram_text', 'telegram_message_id', 'telegram_photo', 'telegram_posted_text', 'telegram_sent_at', 'telegram_error']);
        });
    }
};
