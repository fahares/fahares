<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('suggestable_type', 100)->index();
            $table->unsignedBigInteger('suggestable_id')->index();
            $table->string('field_name', 100)->index();
            $table->text('current_value')->nullable();
            $table->text('suggested_value');
            $table->text('rationale_citation'); // مستند و منبع ادعا

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();

            $table->string('status', 30)->default('pending')->index(); // 'pending', 'approved', 'rejected'
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_suggestions');
    }
};
