<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->string('source_title')->index();
            $table->string('target_title')->index();
            $table->foreignId('target_work_id')->nullable()->constrained('works')->nullOnDelete();
            $table->unsignedTinyInteger('volume_number')->index();
            $table->unsignedSmallInteger('page')->index();
            $table->timestamps();

            $table->index(['volume_number', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
