<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user's choice per notification type and channel; without a row the default in config/notifications.php applies
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('notification_type', 60);
            $table->string('channel', 30);
            $table->boolean('enabled');
            $table->timestamps();
            $table->unique(['user_id', 'notification_type', 'channel'], 'notification_preferences_unique');
        });

        Schema::table('field_suggestions', function (Blueprint $table) {
            // A guest's opt-in to hear the review outcome at guest_email; signed-in users use their preferences
            $table->boolean('notify_submitter')->default(false)->after('credit_name');
        });
    }

    public function down(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->dropColumn('notify_submitter');
        });
        Schema::dropIfExists('notification_preferences');
    }
};
