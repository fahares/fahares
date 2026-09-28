<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarly_annotations', function (Blueprint $table) {
            $table->id();
            $table->string('annotatable_type', 100)->index();
            $table->unsignedBigInteger('annotatable_id')->index();
            $table->string('field_name', 100)->index();
            $table->text('original_fankha_value')->nullable(); // متن اصیل چاپی فنخا
            $table->text('corrected_value'); // مقدار تصحیح‌شده
            $table->string('revision_category', 30)->index(); // 'parser_fix' (الف), 'ocr_fix' (ب), 'scholarly_correction' (ج)
            $table->text('citation_source'); // سند و مستند تصحیح
            $table->string('contributor_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('suggestion_id')->nullable()->constrained('field_suggestions')->nullOnDelete();
            $table->boolean('sync_to_corpus')->default(false); // نیاز به اعمال در fahares-corpus
            $table->boolean('is_synced')->default(false); // آیا در فایل متنی اعمال شد؟
            $table->boolean('is_public')->default(true)->index(); // نمایش پانویس در سایت برای محققان
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarly_annotations');
    }
};
