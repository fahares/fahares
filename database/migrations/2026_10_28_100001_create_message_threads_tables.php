<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Letters between the site's staff and a user (backlog item 91): a reviewer's question on a suggestion before
 * deciding it, and an admin's direct message. One thread per suggestion; the user answers in «پیام‌ها» or by
 * replying to the email. A suggestion asked about stays pending and only leaves the queue while it waits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12); // suggestion | direct
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('field_suggestion_id')->nullable()->unique()->constrained('field_suggestions')->cascadeOnDelete();
            $table->string('subject', 200);
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            // The message_templates key a direct message was sent from by a command, so it is not sent twice
            $table->string('template', 40)->nullable();
            // The address token of the reply-to of its emails
            $table->string('reply_token', 40)->unique();
            $table->boolean('closed')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('user_read_at')->nullable();
            $table->timestamp('staff_read_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'last_message_at']);
            $table->index(['kind', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_staff');
            $table->text('body');
            $table->string('via', 8)->default('site'); // site | email
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('title', 100);
            $table->string('subject', 200);
            $table->text('body');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->timestamp('awaiting_reply_since')->nullable()->after('status')->index();
            $table->timestamp('reply_reminded_at')->nullable()->after('awaiting_reply_since');
            // answered | unanswered: what came of the last question, shown in the queue
            $table->string('reply_state', 12)->nullable()->after('reply_reminded_at');
        });

        $now = now();
        DB::table('message_templates')->insert([
            ['key' => 'welcome', 'title' => 'خوش‌آمد', 'sort' => 1, 'subject' => 'به فهارس خوش آمدید',
                'body' => "{نام} گرامی، سلام.\n\nاز اینکه در فهارس ثبت‌نام کرده‌اید خرسندیم. در صفحه‌ی هر نسخه، اثر یا شخص می‌توانید با «پیشنهاد اصلاح» خطای داده را گزارش کنید یا یادداشت پژوهشی بیفزایید. داوران فهارس هر پیشنهاد را بررسی می‌کنند و نتیجه را به شما خبر می‌دهند.\n\nاگر پرسشی دارید، به همین پیام پاسخ دهید.",
                'created_at' => $now, 'updated_at' => $now],
            ['key' => 'thanks', 'title' => 'تشکر از مشارکت', 'sort' => 2, 'subject' => 'سپاس از مشارکت شما در فهارس',
                'body' => "{نام} گرامی، سلام.\n\nتا امروز {شمار_اصلاحات} پیشنهاد شما در فهارس تأیید شده است. از دقت و همراهی شما سپاسگزاریم؛ این اصلاحات به همه‌ی پژوهشگران نسخه‌های خطی کمک می‌کند.",
                'created_at' => $now, 'updated_at' => $now],
            ['key' => 'persian_name', 'title' => 'درخواست نام فارسی', 'sort' => 3, 'subject' => 'لطفاً نام خود را به فارسی بنویسید',
                'body' => "نام حساب شما در فهارس «{نام}» است و با حروف لاتین نوشته شده است.\n\nاین نام کنار اصلاحات و یادداشت‌هایی که می‌فرستید منتشر می‌شود. لطفاً نام و نام خانوادگی خود را در صفحه‌ی «حساب من» به فارسی بنویسید: {پیوند_حساب}\n\nاگر می‌خواهید نامتان به همین شکل بماند، در نوار بالای صفحه‌های سایت «نامم همین بماند» را بزنید.",
                'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->dropIndex(['awaiting_reply_since']);
            $table->dropColumn(['awaiting_reply_since', 'reply_reminded_at', 'reply_state']);
        });
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
